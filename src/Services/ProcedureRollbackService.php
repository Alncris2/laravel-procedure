<?php

namespace Alncris2\LaravelProcedure\Services;

use Alncris2\LaravelProcedure\Contracts\ProcedureExecutorInterface;
use Alncris2\LaravelProcedure\Models\ProcedureDefinition;
use Alncris2\LaravelProcedure\Repositories\ProcedureVersionRepository;
use RuntimeException;

class ProcedureRollbackService
{
    /** @var ProcedureScanner */
    protected $scanner;

    /** @var ProcedureExecutorInterface */
    protected $executor;

    /** @var ProcedureVersionRepository */
    protected $repository;

    public function __construct(
        ProcedureScanner $scanner,
        ProcedureExecutorInterface $executor,
        ProcedureVersionRepository $repository
    ) {
        $this->scanner = $scanner;
        $this->executor = $executor;
        $this->repository = $repository;
    }

    /**
     * @param string   $procedureName
     * @param int|null $targetVersion
     * @return array
     */
    public function rollbackOne($procedureName, $targetVersion = null)
    {
        $def = $this->scanner->findByName($procedureName);
        if ($def === null) {
            throw new RuntimeException('Procedure não encontrada: ' . $procedureName);
        }
        return $this->rollbackDefinition($def, $targetVersion);
    }

    /**
     * @param string   $group
     * @param int|null $targetVersion
     * @return array
     */
    public function rollbackGroup($group, $targetVersion = null)
    {
        $out = array();
        foreach ($this->scanner->findByGroup($group) as $def) {
            $out[] = $this->rollbackDefinition($def, $targetVersion);
        }
        return $out;
    }

    /**
     * @param ProcedureDefinition $def
     * @param int|null            $targetVersion
     * @return array
     */
    protected function rollbackDefinition(ProcedureDefinition $def, $targetVersion = null)
    {
        $applied = $this->repository->getCurrentApplied($def->group, $def->name);
        if ($applied === null) {
            return array(
                'procedure' => $def->name,
                'group' => $def->group,
                'action' => 'skipped',
                'reason' => 'sem versão atual aplicada',
            );
        }

        // Descobre o alvo
        if ($targetVersion === null) {
            $targetSnap = $this->previousSnapshot($def, $applied->version_number);
            if ($targetSnap === null) {
                // Se existe uma versão anterior no banco mas sem arquivo físico (baseline
                // de importação), dá uma mensagem específica — o rollback não é possível.
                $hasBaselineHistory = $this->repository->getHistory($def->group, $def->name)
                    ->contains(function ($row) use ($applied) {
                        return $row->version_number < $applied->version_number;
                    });
                $reason = $hasBaselineHistory
                    ? 'versão anterior é uma baseline de importação (sem snapshot físico); rollback não é possível'
                    : 'não há versão anterior para rollback';
                return array(
                    'procedure' => $def->name,
                    'group' => $def->group,
                    'action' => 'skipped',
                    'reason' => $reason,
                );
            }
            $targetVersion = $targetSnap->versionNumber;
        } else {
            $targetSnap = $this->snapshotByVersion($def, (int) $targetVersion);
            if ($targetSnap === null) {
                // Pode ser uma baseline de importação que existe só no banco.
                $dbRow = $this->repository->findVersion($def->group, $def->name, (int) $targetVersion);
                if ($dbRow !== null && $dbRow->version_label === ProcedureDumpService::LABEL_IMPORT) {
                    throw new RuntimeException(
                        'Versão ' . $targetVersion . ' é uma baseline de importação de '
                        . $def->name . ' (sem snapshot físico em versions/); rollback não é possível'
                    );
                }
                throw new RuntimeException(
                    'Versão ' . $targetVersion . ' não encontrada em disco para ' . $def->name
                );
            }
        }

        // Executa o SQL do snapshot alvo.
        $result = $this->executor->execute($targetSnap->contents);

        if ($result['status'] !== 'success') {
            return array(
                'procedure' => $def->name,
                'group' => $def->group,
                'action' => 'failed',
                'target_version' => $targetVersion,
                'error_message' => $result['error_message'],
            );
        }

        // Marca a versão corrente como rolled back.
        $this->repository->markRolledBack($applied->id);

        // Acha o registro aplicado da versão alvo no histórico e marca como current.
        $targetRow = $this->repository->findVersion($def->group, $def->name, $targetVersion);
        if ($targetRow !== null) {
            $this->repository->markCurrent($def->group, $def->name, $targetRow->id);
        } else {
            // Cria um novo registro para refletir o estado atual.
            $id = $this->repository->storeAppliedVersion(array(
                'group_name' => $def->group,
                'procedure_name' => $def->name,
                'version_number' => $targetVersion,
                'version_label' => $targetSnap->label,
                'file_name' => $targetSnap->fileName,
                'file_path' => $targetSnap->fullPath,
                'checksum' => $targetSnap->checksum,
                'execution_status' => 'success',
                'execution_time_ms' => $result['execution_time_ms'],
                'error_message' => null,
            ));
            $this->repository->markCurrent($def->group, $def->name, $id);
        }

        return array(
            'procedure' => $def->name,
            'group' => $def->group,
            'action' => 'rolled_back',
            'from_version' => $applied->version_number,
            'to_version' => $targetVersion,
            'execution_time_ms' => $result['execution_time_ms'],
        );
    }

    /**
     * @param ProcedureDefinition $def
     * @param int                 $currentVersion
     * @return \Alncris2\LaravelProcedure\Models\ProcedureSnapshot|null
     */
    protected function previousSnapshot(ProcedureDefinition $def, $currentVersion)
    {
        $best = null;
        foreach ($def->snapshots as $snap) {
            if ($snap->versionNumber < $currentVersion) {
                if ($best === null || $snap->versionNumber > $best->versionNumber) {
                    $best = $snap;
                }
            }
        }
        return $best;
    }

    /**
     * @param ProcedureDefinition $def
     * @param int                 $version
     * @return \Alncris2\LaravelProcedure\Models\ProcedureSnapshot|null
     */
    protected function snapshotByVersion(ProcedureDefinition $def, $version)
    {
        foreach ($def->snapshots as $snap) {
            if ($snap->versionNumber === $version) {
                return $snap;
            }
        }
        return null;
    }
}
