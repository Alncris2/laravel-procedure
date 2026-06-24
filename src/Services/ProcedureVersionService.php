<?php

namespace Alncris2\LaravelProcedure\Services;

use Alncris2\LaravelProcedure\Models\ProcedureDefinition;
use Alncris2\LaravelProcedure\Models\ProcedureSnapshot;

class ProcedureVersionService
{
    /** @var ProcedureScanner */
    protected $scanner;

    /** @var SnapshotService */
    protected $snapshots;

    /** @var ProcedureStatusService */
    protected $status;

    public function __construct(
        ProcedureScanner $scanner,
        SnapshotService $snapshots,
        ProcedureStatusService $status
    ) {
        $this->scanner = $scanner;
        $this->snapshots = $snapshots;
        $this->status = $status;
    }

    /**
     * Cria snapshots para todas as procedures com alterações ou pendentes.
     *
     * @param string|null $message
     * @return array
     */
    public function versionAll($message = null)
    {
        return $this->versionMany($this->scanner->all(), $message);
    }

    /**
     * @param string      $group
     * @param string|null $message
     * @return array
     */
    public function versionGroup($group, $message = null)
    {
        return $this->versionMany($this->scanner->findByGroup($group), $message);
    }

    /**
     * @param string      $procedureName
     * @param string|null $message
     * @return array
     */
    public function versionOne($procedureName, $message = null)
    {
        $def = $this->scanner->findByName($procedureName);
        if ($def === null) {
            throw new \RuntimeException('Procedure não encontrada: ' . $procedureName);
        }
        return $this->versionMany(array($def), $message);
    }

    /**
     * @param ProcedureDefinition[] $definitions
     * @param string|null           $message
     * @return array
     */
    protected function versionMany(array $definitions, $message = null)
    {
        $results = array();
        foreach ($definitions as $def) {
            $results[] = $this->versionDefinition($def, $message);
        }
        return $results;
    }

    /**
     * @param ProcedureDefinition $def
     * @param string|null         $message
     * @return array
     */
    protected function versionDefinition(ProcedureDefinition $def, $message = null)
    {
        if (!$def->hasCurrent()) {
            return array(
                'procedure' => $def->name,
                'group' => $def->group,
                'action' => 'skipped',
                'reason' => 'current.sql ausente',
                'file' => '',
            );
        }

        $status = $this->status->statusFor($def);

        if ($status['status'] === ProcedureStatusService::STATUS_SYNCED) {
            return array(
                'procedure' => $def->name,
                'group' => $def->group,
                'action' => 'skipped',
                'reason' => 'já sincronizado',
                'file' => '',
            );
        }

        try {
            $snap = $this->snapshots->createFromCurrent($def, $message);
            return array(
                'procedure' => $def->name,
                'group' => $def->group,
                'action' => 'versioned',
                'reason' => '',
                'file' => $snap->fileName,
            );
        } catch (\Exception $e) {
            return array(
                'procedure' => $def->name,
                'group' => $def->group,
                'action' => 'failed',
                'reason' => $e->getMessage(),
                'file' => '',
            );
        }
    }
}
