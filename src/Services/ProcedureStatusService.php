<?php

namespace Alncri2\LaravelProcedure\Services;

use Alncri2\LaravelProcedure\Models\ProcedureDefinition;
use Alncri2\LaravelProcedure\Repositories\ProcedureVersionRepository;
use Alncri2\LaravelProcedure\Support\Checksum;

class ProcedureStatusService
{
    const STATUS_SYNCED = 'SYNCED';
    const STATUS_CHANGED = 'CHANGED';
    const STATUS_PENDING = 'PENDING';
    const STATUS_FAILED = 'FAILED';
    const STATUS_UNTRACKED = 'UNTRACKED';

    /** @var ProcedureScanner */
    protected $scanner;

    /** @var ProcedureVersionRepository */
    protected $repository;

    public function __construct(ProcedureScanner $scanner, ProcedureVersionRepository $repository)
    {
        $this->scanner = $scanner;
        $this->repository = $repository;
    }

    /**
     * Retorna array de arrays com keys:
     *   group, procedure, status, applied_version, current_checksum, applied_checksum.
     *
     * @return array
     */
    public function getAllStatuses()
    {
        $out = array();
        foreach ($this->scanner->all() as $def) {
            $out[] = $this->statusFor($def);
        }
        return $out;
    }

    /**
     * @return array
     */
    public function getChanged()
    {
        $out = array();
        foreach ($this->getAllStatuses() as $row) {
            if ($row['status'] !== self::STATUS_SYNCED) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param ProcedureDefinition $def
     * @return array
     */
    public function statusFor(ProcedureDefinition $def)
    {
        $currentContents = $def->hasCurrent() ? $def->readCurrent() : '';
        $currentChecksum = $def->hasCurrent() ? Checksum::hash($currentContents) : null;

        $applied = $this->repository->getCurrentApplied($def->group, $def->name);
        $latest = $this->repository->getLatestVersion($def->group, $def->name);

        $status = self::STATUS_UNTRACKED;
        $appliedVersion = null;
        $appliedChecksum = null;

        if ($applied !== null) {
            $appliedVersion = $applied->version_number;
            $appliedChecksum = $applied->checksum;

            if ($applied->execution_status !== 'success') {
                $status = self::STATUS_FAILED;
            } elseif ($currentChecksum !== null && $currentChecksum === $applied->checksum) {
                $status = self::STATUS_SYNCED;
            } else {
                $status = self::STATUS_CHANGED;
            }
        } else {
            if ($latest !== null && $latest->execution_status === 'failed') {
                $status = self::STATUS_FAILED;
                $appliedVersion = $latest->version_number;
                $appliedChecksum = $latest->checksum;
            } elseif ($def->hasCurrent()) {
                $status = self::STATUS_PENDING;
            } else {
                $status = self::STATUS_UNTRACKED;
            }
        }

        return array(
            'group' => $def->group,
            'procedure' => $def->name,
            'status' => $status,
            'applied_version' => $appliedVersion,
            'current_checksum' => $currentChecksum,
            'applied_checksum' => $appliedChecksum,
            'definition' => $def,
        );
    }
}
