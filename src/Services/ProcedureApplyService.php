<?php

namespace Alncris2\LaravelProcedure\Services;

use Alncris2\LaravelProcedure\Contracts\ProcedureExecutorInterface;
use Alncris2\LaravelProcedure\Models\ProcedureDefinition;
use Alncris2\LaravelProcedure\Models\ProcedureSnapshot;
use Alncris2\LaravelProcedure\Repositories\ProcedureVersionRepository;
use Alncris2\LaravelProcedure\Support\Checksum;
use RuntimeException;

class ProcedureApplyService
{
    /** @var ProcedureScanner */
    protected $scanner;

    /** @var ProcedureExecutorInterface */
    protected $executor;

    /** @var ProcedureVersionRepository */
    protected $repository;

    /** @var ProcedureStatusService */
    protected $status;

    public function __construct(
        ProcedureScanner $scanner,
        ProcedureExecutorInterface $executor,
        ProcedureVersionRepository $repository,
        ProcedureStatusService $status
    ) {
        $this->scanner = $scanner;
        $this->executor = $executor;
        $this->repository = $repository;
        $this->status = $status;
    }

    /**
     * @param string|null $message
     * @return array resultados por procedure
     */
    public function applyAll($message = null)
    {
        return $this->applyMany($this->scanner->all(), $message);
    }

    /**
     * @param string      $group
     * @param string|null $message
     * @return array
     */
    public function applyGroup($group, $message = null)
    {
        return $this->applyMany($this->scanner->findByGroup($group), $message);
    }

    /**
     * @param string      $procedureName
     * @param string|null $message
     * @return array
     */
    public function applyOne($procedureName, $message = null)
    {
        $def = $this->scanner->findByName($procedureName);
        if ($def === null) {
            throw new RuntimeException('Procedure não encontrada: ' . $procedureName);
        }
        return $this->applyMany(array($def), $message);
    }

    /**
     * @param ProcedureDefinition[] $definitions
     * @param string|null           $message
     * @return array
     */
    protected function applyMany(array $definitions, $message = null)
    {
        $results = array();
        foreach ($definitions as $def) {
            $results[] = $this->applyDefinition($def, $message);
        }
        return $results;
    }

    /**
     * @param ProcedureDefinition $def
     * @param string|null         $message
     * @return array
     */
    protected function applyDefinition(ProcedureDefinition $def, $message = null)
    {
        if (!$def->hasCurrent()) {
            return array(
                'procedure' => $def->name,
                'group' => $def->group,
                'action' => 'skipped',
                'reason' => 'current.sql ausente',
            );
        }

        $status = $this->status->statusFor($def);

        if ($status['status'] === ProcedureStatusService::STATUS_SYNCED) {
            return array(
                'procedure' => $def->name,
                'group' => $def->group,
                'action' => 'skipped',
                'reason' => 'já sincronizado',
            );
        }

        $contents = $def->readCurrent();
        $checksum = Checksum::hash($contents);

        // Usa o snapshot existente no disco que bata com o current.sql (criado por procedure:version),
        // caso contrário referencia o próprio current.sql.
        $snap = $this->findMatchingSnapshot($def, $checksum);

        $result = $this->executor->execute($contents);

        // version_number do banco é sempre max(DB)+1, independente da posição no disco.
        $dbVersionNumber = $this->repository->getNextVersionNumber($def->group, $def->name);

        $label = $snap ? $snap->label : ($message ? $message : config('procedure.default_snapshot_message', 'apply'));
        $fileName = $snap ? $snap->fileName : 'current.sql';
        $filePath = $snap ? $snap->fullPath : $def->currentPath;

        $id = $this->repository->storeAppliedVersion(array(
            'group_name' => $def->group,
            'procedure_name' => $def->name,
            'version_number' => $dbVersionNumber,
            'version_label' => $label,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'checksum' => $checksum,
            'execution_status' => $result['status'],
            'execution_time_ms' => $result['execution_time_ms'],
            'error_message' => $result['error_message'],
        ));

        if ($result['status'] === 'success') {
            $this->repository->markCurrent($def->group, $def->name, $id);
        }

        return array(
            'procedure' => $def->name,
            'group' => $def->group,
            'action' => 'applied',
            'version' => $dbVersionNumber,
            'file' => $fileName,
            'status' => $result['status'],
            'execution_time_ms' => $result['execution_time_ms'],
            'error_message' => $result['error_message'],
        );
    }

    /**
     * Retorna o snapshot mais recente cujo checksum bate com o current.sql.
     * Usado para associar o apply ao arquivo de versão criado por procedure:version.
     *
     * @param ProcedureDefinition $def
     * @param string              $checksum
     * @return ProcedureSnapshot|null
     */
    protected function findMatchingSnapshot(ProcedureDefinition $def, $checksum)
    {
        if (empty($def->snapshots)) {
            return null;
        }
        $latest = end($def->snapshots);
        return ($latest && $latest->checksum === $checksum) ? $latest : null;
    }
}
