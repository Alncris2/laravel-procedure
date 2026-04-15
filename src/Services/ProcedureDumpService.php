<?php

namespace Alncris2\LaravelProcedure\Services;

use Alncris2\LaravelProcedure\Contracts\ProcedureExecutorInterface;
use Alncris2\LaravelProcedure\Contracts\ProcedureSourceReaderInterface;
use Alncris2\LaravelProcedure\Models\ProcedureDefinition;
use Alncris2\LaravelProcedure\Models\ProcedureSnapshot;
use Alncris2\LaravelProcedure\Repositories\ProcedureVersionRepository;
use Alncris2\LaravelProcedure\Support\Checksum;
use Alncris2\LaravelProcedure\Support\Slugger;
use RuntimeException;

class ProcedureDumpService
{
    /** Label usado na linha de baseline (procedure importada pela primeira vez, sem arquivo em versions/). */
    const LABEL_IMPORT = 'dump_import';

    /** Label usado nos snapshots gerados quando o dump detecta divergência entre banco e disco. */
    const LABEL_SYNC = 'dump_sync';

    /** @deprecated use LABEL_IMPORT */
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

    /** @var AutoGroupResolver|null */
    protected $autoGroup;

    public function __construct(
        ProcedureSourceReaderInterface $reader,
        ProcedureScanner $scanner,
        SnapshotService $snapshots,
        ProcedureExecutorInterface $executor,
        ProcedureVersionRepository $repository,
        AutoGroupResolver $autoGroup = null
    ) {
        $this->reader = $reader;
        $this->scanner = $scanner;
        $this->snapshots = $snapshots;
        $this->executor = $executor;
        $this->repository = $repository;
        $this->autoGroup = $autoGroup;
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

        $readerOptions = array();
        if (isset($options['only']) && $options['only']) {
            $readerOptions['only'] = $options['only'];
        }
        if (isset($options['owner']) && $options['owner']) {
            $readerOptions['owner'] = $options['owner'];
        }
        $register = !array_key_exists('register', $options) ? true : (bool) $options['register'];

        // Auto-group quando não for informado grupo explícito.
        if ($group === null || $group === '') {
            return $this->dumpAllAuto($readerOptions, $register, $options);
        }

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
     * Calcula proposta de auto-group sem gravar nada. Usado pelo --group vazio (dry-run).
     *
     * @param array $options Mesmas chaves aceitas por dumpAll().
     * @return array<int, array{name: string, group: string, strategy: string, tables: array}>
     */
    public function planAutoGroup(array $options = array())
    {
        if (!$this->reader->supportsDump()) {
            throw new RuntimeException(
                'Driver atual não suporta dump: ' . $this->reader->driver()
            );
        }
        $readerOptions = array();
        if (isset($options['only']) && $options['only']) {
            $readerOptions['only'] = $options['only'];
        }
        if (isset($options['owner']) && $options['owner']) {
            $readerOptions['owner'] = $options['owner'];
        }

        $strategy = isset($options['strategy']) && $options['strategy']
            ? (string) $options['strategy']
            : AutoGroupResolver::STRATEGY_CASCADE;

        $procedures = $this->loadProceduresForAutoGroup($readerOptions);
        $resolved = $this->getAutoGroupResolver()->resolve($procedures, $strategy);

        $plan = array();
        foreach ($procedures as $proc) {
            $name = $proc['name'];
            $info = isset($resolved[$name]) ? $resolved[$name] : array('group' => 'ungrouped', 'strategy' => 'fallback', 'tables' => array());
            $plan[] = array(
                'name' => $name,
                'group' => $info['group'],
                'strategy' => $info['strategy'],
                'tables' => $info['tables'],
            );
        }
        return $plan;
    }

    /**
     * Executa o dump agrupando cada procedure pelo grupo inferido.
     */
    protected function dumpAllAuto(array $readerOptions, $register, array $options)
    {
        $strategy = isset($options['strategy']) && $options['strategy']
            ? (string) $options['strategy']
            : AutoGroupResolver::STRATEGY_CASCADE;

        $procedures = $this->loadProceduresForAutoGroup($readerOptions);
        $resolved = $this->getAutoGroupResolver()->resolve($procedures, $strategy);

        $results = array();
        foreach ($procedures as $proc) {
            $name = $proc['name'];
            $group = isset($resolved[$name]['group']) ? $resolved[$name]['group'] : 'ungrouped';
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
     * @param array $readerOptions
     * @return array<int, array{name: string, owner: ?string, source: string}>
     */
    protected function loadProceduresForAutoGroup(array $readerOptions)
    {
        $listed = $this->reader->listProceduresDetailed($readerOptions);
        $out = array();
        foreach ($listed as $entry) {
            $name = isset($entry['name']) ? $entry['name'] : null;
            if ($name === null) {
                continue;
            }
            try {
                $source = $this->reader->getProcedureSource($name, $readerOptions);
            } catch (\Exception $e) {
                $source = '';
            }
            $out[] = array(
                'name' => $name,
                'owner' => isset($entry['owner']) ? $entry['owner'] : null,
                'source' => $source,
            );
        }
        return $out;
    }

    /**
     * @return AutoGroupResolver
     */
    protected function getAutoGroupResolver()
    {
        if ($this->autoGroup === null) {
            $this->autoGroup = new AutoGroupResolver();
        }
        return $this->autoGroup;
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

        // 1) Primeira importação — baseline silenciosa: current.sql + linha no histórico,
        //    sem arquivo em versions/.
        if (!$def->hasCurrent()) {
            $this->ensureDirs($def);
            $this->writeCurrent($def, $source);
            $def = $this->scanner->buildDefinition($group, $name);

            if ($register) {
                $this->registerBaseline($def, $newChecksum);
            }

            return array(
                'group' => $group,
                'procedure' => $name,
                'result' => self::RESULT_CREATED,
                'version' => '',
                'file' => '',
            );
        }

        // 2) Estrutura existe — compara checksum do current.sql vs fonte do banco.
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

        // 3) Diverge — atualiza current.sql e cria snapshot NNN_dump_sync.sql
        //    (essa sim é uma mudança real vinda do banco que merece versionamento físico).
        $this->writeCurrent($def, $source);
        $def = $this->scanner->buildDefinition($group, $name);
        $snap = $this->createSyncSnapshot($def);

        if ($register) {
            $this->registerSync($def, $snap);
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
     * Cria snapshot físico em versions/NNN_dump_sync.sql usando numeração
     * que considera todo o histórico no banco (inclui baselines sem arquivo).
     *
     * @param ProcedureDefinition $def
     * @return ProcedureSnapshot
     */
    protected function createSyncSnapshot(ProcedureDefinition $def)
    {
        if (!$def->hasCurrent()) {
            throw new RuntimeException(
                'current.sql não encontrado para ' . $def->name
                . ' (esperado em ' . $def->currentPath . ')'
            );
        }

        $this->ensureDirs($def);

        $padding = (int) config('procedure.version_padding', 3);
        if ($padding < 1) {
            $padding = 3;
        }

        $nextNumber = $this->repository->getNextVersionNumber($def->group, $def->name);
        $label = Slugger::slug(self::LABEL_SYNC, self::LABEL_SYNC);
        $fileName = str_pad((string) $nextNumber, $padding, '0', STR_PAD_LEFT) . '_' . $label . '.sql';
        $fullPath = $def->versionsPath . DIRECTORY_SEPARATOR . $fileName;

        $contents = $def->readCurrent();
        if (file_put_contents($fullPath, $contents) === false) {
            throw new RuntimeException('Falha ao gravar snapshot: ' . $fullPath);
        }

        $snap = new ProcedureSnapshot(
            $nextNumber,
            $label,
            $fileName,
            $fullPath,
            $contents,
            Checksum::hash($contents)
        );
        $def->snapshots[] = $snap;
        return $snap;
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
     * Registra a baseline de importação em procedure_versions SEM criar
     * arquivo físico em versions/. A linha serve apenas como baseline de
     * checksum para o status: current.sql está em sync com o banco a partir
     * desse ponto. file_path aponta para o próprio current.sql.
     *
     * @param ProcedureDefinition $def
     * @param string              $checksum Checksum do SQL normalizado.
     * @return void
     */
    protected function registerBaseline(ProcedureDefinition $def, $checksum)
    {
        $nextNumber = $this->repository->getNextVersionNumber($def->group, $def->name);
        $id = $this->repository->storeAppliedVersion(array(
            'group_name' => $def->group,
            'procedure_name' => $def->name,
            'version_number' => $nextNumber,
            'version_label' => self::LABEL_IMPORT,
            'file_name' => 'current.sql',
            'file_path' => $def->currentPath,
            'checksum' => $checksum,
            'execution_status' => 'success',
            'execution_time_ms' => 0,
            'error_message' => null,
        ));
        $this->repository->markCurrent($def->group, $def->name, $id);
    }

    /**
     * Registra a versão dump_sync (snapshot físico criado porque o banco
     * divergiu do disco).
     *
     * @param ProcedureDefinition $def
     * @param ProcedureSnapshot   $snap
     * @return void
     */
    protected function registerSync(ProcedureDefinition $def, ProcedureSnapshot $snap)
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
