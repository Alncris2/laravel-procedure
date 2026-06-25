<?php

namespace Alncris2\LaravelProcedure\Console\Commands;

use Alncris2\LaravelProcedure\Services\ProcedureStatusService;
use Illuminate\Console\Command;

class StatusProcedureCommand extends Command
{
    protected $signature = 'procedure:status
                            {--group= : Filtra por grupo}
                            {--changed : Mostra apenas procedures que não estão SYNCED}
                            {--debug : Exibe checksums para diagnóstico de divergências}';

    protected $description = 'Lista o status de todas as procedures (SYNCED, CHANGED, PENDING, FAILED).';

    public function handle(ProcedureStatusService $status)
    {
        $rows = $status->getAllStatuses();

        $group = $this->option('group');
        if ($group !== null && $group !== '') {
            $rows = array_values(array_filter($rows, function ($r) use ($group) {
                return $r['group'] === $group;
            }));
        }

        if ($this->option('changed')) {
            $rows = array_values(array_filter($rows, function ($r) {
                return $r['status'] !== ProcedureStatusService::STATUS_SYNCED;
            }));
        }

        if (empty($rows)) {
            $this->info('Nenhuma procedure encontrada.');
            return 0;
        }

        $debug = (bool) $this->option('debug');

        $display = array();
        foreach ($rows as $r) {
            $row = array(
                'group'           => $r['group'],
                'procedure'       => $r['procedure'],
                'status'          => $r['status'],
                'applied_version' => $r['applied_version'],
            );

            if ($debug) {
                $row['current_checksum'] = $r['current_checksum']
                    ? substr($r['current_checksum'], 0, 12) . '…'
                    : '(none)';
                $row['applied_checksum'] = $r['applied_checksum']
                    ? substr($r['applied_checksum'], 0, 12) . '…'
                    : '(none)';
                $row['match'] = ($r['current_checksum'] && $r['current_checksum'] === $r['applied_checksum'])
                    ? 'YES'
                    : 'NO';
            }

            $display[] = $row;
        }

        $headers = $debug
            ? array('group', 'procedure', 'status', 'applied_version', 'current_checksum', 'applied_checksum', 'match')
            : array('group', 'procedure', 'status', 'applied_version');

        $this->table($headers, $display);
        return 0;
    }
}
