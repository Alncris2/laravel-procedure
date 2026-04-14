<?php

namespace Alncris2\LaravelProcedure\Services;

use Alncris2\LaravelProcedure\Contracts\ProcedureExecutorInterface;
use Alncris2\LaravelProcedure\Models\ProcedureDefinition;
use Alncris2\LaravelProcedure\Repositories\ProcedureVersionRepository;
use Alncris2\LaravelProcedure\Support\Checksum;
use RuntimeException;

class ProcedureApplyService
{
    /** @var ProcedureScanner */
    protected $scanner;

    /** @var SnapshotService */
    protected $snapshots;

    /** @var ProcedureExecutorInterface */
    protected $executor;

    /** @var ProcedureVersionRepository */
    protected $repository;

    /** @var ProcedureStatusService */
    protected $status;

    public function __construct(
        ProcedureScanner $scanner,
        SnapshotService $snapshots,
        ProcedureExecutorInterface $executor,
        ProcedureVersionRepository $repository,
        ProcedureStatusService $status
    ) {
        $this->scanner = $scanner;
        $this->snapshots = $snapshots;
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
        $status = $this->status->statusFor($def);

        if (!$def->hasCurrent()) {
            return array(
                'procedure' => $def->name,
                'group' => $def->group,
                'action' => 'skipped',
                'reason' => 'current.sql ausente',
            );
        }

        if ($status['status'] === ProcedureStatusService::STATUS_SYNCED) {
            return array(
                'procedure' => $def->name,
                'group' => $def->group,
                'action' => 'skipped',
                'reason' => 'já sincronizado',
            );
        }

        $shouldSnapshot = (bool) config('procedure.snapshot_on_apply', true);
        $snapshot = null;
        if ($shouldSnapshot) {
            $snapshot = $this->snapshots->createFromCurrent($def, $message);
        } else {
            // sem snapshot, usa o próximo número só para registrar
            $next = $this->snapshots->getNextVersionNumber($def);
            $contents = $def->readCurrent();
            $snapshot = new \Alncris2\LaravelProcedure\Models\ProcedureSnapshot(
                $next,
                null,
                'current.sql',
                $def->currentPath,
                $contents,
                Checksum::hash($contents)
            );
        }

        $result = $this->executor->execute($snapshot->contents);

        $id = $this->repository->storeAppliedVersion(array(
            'group_name' => $def->group,
            'procedure_name' => $def->name,
            'version_number' => $snapshot->versionNumber,
            'version_label' => $snapshot->label,
            'file_name' => $snapshot->fileName,
            'file_path' => $snapshot->fullPath,
            'checksum' => $snapshot->checksum,
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
            'version' => $snapshot->versionNumber,
            'file' => $snapshot->fileName,
            'status' => $result['status'],
            'execution_time_ms' => $result['execution_time_ms'],
            'error_message' => $result['error_message'],
        );
    }
}
