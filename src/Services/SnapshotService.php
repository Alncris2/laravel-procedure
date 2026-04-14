<?php

namespace Alncris2\LaravelProcedure\Services;

use Alncris2\LaravelProcedure\Models\ProcedureDefinition;
use Alncris2\LaravelProcedure\Models\ProcedureSnapshot;
use Alncris2\LaravelProcedure\Support\Checksum;
use Alncris2\LaravelProcedure\Support\Slugger;
use RuntimeException;

class SnapshotService
{
    /**
     * @param ProcedureDefinition $procedure
     * @return int
     */
    public function getNextVersionNumber(ProcedureDefinition $procedure)
    {
        $latest = $procedure->latestSnapshot();
        if ($latest === null) {
            return 1;
        }
        return $latest->versionNumber + 1;
    }

    /**
     * Cria um snapshot copiando o current.sql para versions/NNN_label.sql.
     *
     * @param ProcedureDefinition $procedure
     * @param string|null         $message
     * @return ProcedureSnapshot
     */
    public function createFromCurrent(ProcedureDefinition $procedure, $message = null)
    {
        if (!$procedure->hasCurrent()) {
            throw new RuntimeException(
                'current.sql não encontrado para ' . $procedure->name
                . ' (esperado em ' . $procedure->currentPath . ')'
            );
        }

        if (!is_dir($procedure->versionsPath)) {
            if (!@mkdir($procedure->versionsPath, 0775, true) && !is_dir($procedure->versionsPath)) {
                throw new RuntimeException('Falha ao criar diretório de versões: ' . $procedure->versionsPath);
            }
        }

        $padding = (int) config('procedure.version_padding', 3);
        if ($padding < 1) {
            $padding = 3;
        }
        $defaultMessage = config('procedure.default_snapshot_message', 'auto_snapshot');

        $nextNumber = $this->getNextVersionNumber($procedure);
        $label = Slugger::slug($message, $defaultMessage);
        $fileName = str_pad((string) $nextNumber, $padding, '0', STR_PAD_LEFT) . '_' . $label . '.sql';
        $fullPath = $procedure->versionsPath . DIRECTORY_SEPARATOR . $fileName;

        $contents = $procedure->readCurrent();
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

        $procedure->snapshots[] = $snap;

        return $snap;
    }
}
