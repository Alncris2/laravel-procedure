<?php

namespace Alncris2\LaravelProcedure\Services;

use Alncris2\LaravelProcedure\Contracts\ProcedureExecutorInterface;
use Alncris2\LaravelProcedure\Contracts\ProcedureSourceReaderInterface;
use Alncris2\LaravelProcedure\Repositories\ProcedureVersionRepository;
use Alncris2\LaravelProcedure\Support\Checksum;
use Alncris2\LaravelProcedure\Support\Slugger;
use RuntimeException;

class ProcedureDumpService
{
    const LABEL_PREFIX = 'dump_import';

    const RESULT_CREATED = 'created';
    const RESULT_UPDATED = 'updated';
    const RESULT_SYNCED = 'synced';
    const RESULT_FAILED = 'failed';

    /** @var ProcedureSourceReaderInterface */
    protected $reader;

    /** @var ProcedureScanner */
    protected $scanner;

    /** @var SnapshotService */
    protected $snapshots;

    /** @var ProcedureExecutorInterface */
    protected $executor;

    /** @var ProcedureVersionRepository */
    protected $repository;

    public function __construct(
        ProcedureSourceReaderInterface $reader,
        ProcedureScanner $scanner,
        SnapshotService $snapshots,
        ProcedureExecutorInterface $executor,
        ProcedureVersionRepository $repository
    ) {
        $this->reader = $reader;
        $this->scanner = $scanner;
        $this->snapshots = $snapshots;
        $this->executor = $executor;
        $this->repository = $repository;
    }

    /**
     * Faz dump de todas as procedures do banco para o grupo alvo.
     *
     * @param string $group
     * @param array  $options {
     *     @var string|null $only   Nome específico de procedure
     *     @var string|null $owner  Owner/schema (oracle)
     *     @var bool        $register  Se true, registra entrada em procedure_versions
     * }
     * @return array[]
     */
    public function dumpAll($group, array $options = array())
    {
        if (!$this->reader->supportsDump()) {
            throw new RuntimeException(
                'Driver atual não suporta dump: ' . $this->reader->driver()
            );
        }

        if ($group === null || $group === '') {
            throw new RuntimeException('Informe um grupo de destino para o dump.');
        }

        $readerOptions = array();
        if (isset($options['only']) && $options['only']) {
            $readerOptions['only'] = $options['only'];
        }
        if (isset($options['owner']) && $options['owner']) {
            $readerOptions['owner'] = $options['owner'];
        }
        $register = !array_key_exists('register', $options) ? true : (bool) $options['register'];

        $names = $this->reader->listProcedures($readerOptions);
        $results = array();

        foreach ($names as $name) {
            try {
                $results[] = $this->dumpOne($group, $name, $readerOptions, $register);
            } catch (\Exception $e) {
                $results[] = array(
                    'group' => $group,
                    'procedure' => $name,
                    'result' => self::RESULT_FAILED,
                    'error' => $e->getMessage(),
                );
            }
        }

        return $results;
    }

    /**
     * @param string $group
     * @param string $name
     * @param array  $readerOptions
     * @param bool   $register
     * @return array
     */
    protected function dumpOne($group, $name, array $readerOptions, $register)
    {
        $source = $this->reader->getProcedureSource($name, $readerOptions);
        $normalized = $this->executor->normalize($source);
        $newChecksum = Checksum::hash($normalized);

        $def = $this->scanner->buildDefinition($group, $name);

        // 1) estrutura ainda não existe
        if (!$def->hasCurrent()) {
            $this->ensureDirs($def);
            $this->writeCurrent($def, $source);

            // refaz a definition para pegar caminhos e snapshots atualizados.
            $def = $this->scanner->buildDefinition($group, $name);

            $snap = $this->snapshots->createFromCurrent($def, self::LABEL_PREFIX);

            if ($register) {
                $this->registerImport($def, $snap);
            }

            return array(
                'group' => $group,
                'procedure' => $name,
                'result' => self::RESULT_CREATED,
                'version' => $snap->versionNumber,
                'file' => $snap->fileName,
            );
        }

        // 2) estrutura existe — compara checksum do current.sql vs fonte do banco.
        $currentContents = $def->readCurrent();
        $currentNormalized = $this->executor->normalize($currentContents);
        $currentChecksum = Checksum::hash($currentNormalized);

        if ($currentChecksum === $newChecksum) {
            return array(
                'group' => $group,
                'procedure' => $name,
                'result' => self::RESULT_SYNCED,
            );
        }

        // 3) diverge — atualiza current.sql e cria snapshot dump_import.
        $this->writeCurrent($def, $source);
        $def = $this->scanner->buildDefinition($group, $name);
        $snap = $this->snapshots->createFromCurrent($def, self::LABEL_PREFIX);

        if ($register) {
            $this->registerImport($def, $snap);
        }

        return array(
            'group' => $group,
            'procedure' => $name,
            'result' => self::RESULT_UPDATED,
            'version' => $snap->versionNumber,
            'file' => $snap->fileName,
        );
    }

    /**
     * @param \Alncris2\LaravelProcedure\Models\ProcedureDefinition $def
     * @return void
     */
    protected function ensureDirs($def)
    {
        if (!is_dir($def->versionsPath)) {
            if (!@mkdir($def->versionsPath, 0775, true) && !is_dir($def->versionsPath)) {
                throw new RuntimeException('Falha ao criar: ' . $def->versionsPath);
            }
        }
    }

    /**
     * @param \Alncris2\LaravelProcedure\Models\ProcedureDefinition $def
     * @param string                                               $contents
     * @return void
     */
    protected function writeCurrent($def, $contents)
    {
        $this->ensureDirs($def);
        if (file_put_contents($def->currentPath, $contents) === false) {
            throw new RuntimeException('Falha ao gravar: ' . $def->currentPath);
        }
    }

    /**
     * Registra a importação como uma linha "current" em procedure_versions,
     * assumindo que o conteúdo já está vigente no banco (porque foi lido de lá).
     *
     * @param \Alncris2\LaravelProcedure\Models\ProcedureDefinition $def
     * @param \Alncris2\LaravelProcedure\Models\ProcedureSnapshot   $snap
     * @return void
     */
    protected function registerImport($def, $snap)
    {
        $id = $this->repository->storeAppliedVersion(array(
            'group_name' => $def->group,
            'procedure_name' => $def->name,
            'version_number' => $snap->versionNumber,
            'version_label' => $snap->label,
            'file_name' => $snap->fileName,
            'file_path' => $snap->fullPath,
            'checksum' => $snap->checksum,
            'execution_status' => 'success',
            'execution_time_ms' => 0,
            'error_message' => null,
        ));
        $this->repository->markCurrent($def->group, $def->name, $id);
    }
}
