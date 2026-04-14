<?php

namespace Alncris2\LaravelProcedure\Console\Commands;

use Alncris2\LaravelProcedure\Services\ProcedureStatusService;
use Illuminate\Console\Command;

class StatusProcedureCommand extends Command
{
    protected $signature = 'procedure:status
                            {--group= : Filtra por grupo}
                            {--changed : Mostra apenas procedures que não estão SYNCED}';

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

        $display = array();
        foreach ($rows as $r) {
            $display[] = array(
                'group' => $r['group'],
                'procedure' => $r['procedure'],
                'status' => $r['status'],
                'applied_version' => $r['applied_version'],
            );
        }

        $this->table(
            array('group', 'procedure', 'status', 'applied_version'),
            $display
        );
        return 0;
    }
}
