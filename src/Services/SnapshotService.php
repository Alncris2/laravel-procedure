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
     * Cria um snapshot copiando o current.sql para versions/YYYYMMdd_HHmmss_label.sql.
     * Após gravar, aplica o rolling window removendo os snapshots mais antigos
     * caso o limite configurado em procedure.max_snapshots seja excedido.
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

        $defaultMessage = config('procedure.default_snapshot_message', 'auto_snapshot');
        $label = Slugger::slug($message, $defaultMessage);
        $timestamp = date('Ymd_His');
        $fileName = $timestamp . '_' . $label . '.sql';
        $fullPath = $procedure->versionsPath . DIRECTORY_SEPARATOR . $fileName;

        $contents = $procedure->readCurrent();
        if (file_put_contents($fullPath, $contents) === false) {
            throw new RuntimeException('Falha ao gravar snapshot: ' . $fullPath);
        }

        $nextPosition = count($procedure->snapshots) + 1;
        $snap = new ProcedureSnapshot(
            $nextPosition,
            $label,
            $fileName,
            $fullPath,
            $contents,
            Checksum::hash($contents)
        );

        $procedure->snapshots[] = $snap;

        $this->pruneOldSnapshots($procedure);

        return $snap;
    }

    /**
     * Remove os snapshots mais antigos do disco quando o número de arquivos
     * em versions/ excede procedure.max_snapshots.
     * Arquivos são ordenados pelo nome (YYYYMMdd_HHmmss prefix ordena corretamente).
     *
     * @param ProcedureDefinition $procedure
     * @return void
     */
    public function pruneOldSnapshots(ProcedureDefinition $procedure)
    {
        $max = (int) config('procedure.max_snapshots', 5);
        if ($max <= 0) {
            return;
        }

        if (!is_dir($procedure->versionsPath)) {
            return;
        }

        $files = scandir($procedure->versionsPath);
        if ($files === false) {
            return;
        }

        $sqlFiles = array();
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            if (substr($file, -4) === '.sql') {
                $sqlFiles[] = $file;
            }
        }

        sort($sqlFiles);

        $excess = count($sqlFiles) - $max;
        for ($i = 0; $i < $excess; $i++) {
            @unlink($procedure->versionsPath . DIRECTORY_SEPARATOR . $sqlFiles[$i]);
        }
    }
}
