<?php

namespace Alncris2\LaravelProcedure\Console\Commands;

use Alncris2\LaravelProcedure\Services\ProcedureApplyService;
use Illuminate\Console\Command;

class ApplyProcedureCommand extends Command
{
    protected $signature = 'procedure:apply
                            {--only= : Aplica apenas uma procedure pelo nome}
                            {--group= : Aplica apenas procedures de um grupo}
                            {--message= : Mensagem usada para nomear o snapshot gerado}';

    protected $description = 'Aplica as procedures pendentes/alteradas ao banco, gerando snapshots automaticamente.';

    public function handle(ProcedureApplyService $apply)
    {
        $only = $this->option('only');
        $group = $this->option('group');
        $message = $this->option('message');

        if ($only) {
            $results = $apply->applyOne($only, $message);
        } elseif ($group) {
            $results = $apply->applyGroup($group, $message);
        } else {
            $results = $apply->applyAll($message);
        }

        if (empty($results)) {
            $this->info('Nenhuma procedure encontrada.');
            return 0;
        }

        $rows = array();
        $hadFailure = false;
        foreach ($results as $r) {
            $action = isset($r['action']) ? $r['action'] : '';
            $status = isset($r['status']) ? $r['status'] : (isset($r['reason']) ? $r['reason'] : '');
            $version = isset($r['version']) ? $r['version'] : '';
            $time = isset($r['execution_time_ms']) ? $r['execution_time_ms'] : '';

            if ($action === 'applied' && isset($r['status']) && $r['status'] !== 'success') {
                $hadFailure = true;
                $this->error(sprintf(
                    '[%s] %s v%s FALHOU: %s',
                    $r['group'],
                    $r['procedure'],
                    $version,
                    isset($r['error_message']) ? $r['error_message'] : 'erro desconhecido'
                ));
            }

            $rows[] = array(
                'group' => $r['group'],
                'procedure' => $r['procedure'],
                'action' => $action,
                'version' => $version,
                'status' => $status,
                'ms' => $time,
            );
        }

        $this->table(
            array('group', 'procedure', 'action', 'version', 'status', 'ms'),
            $rows
        );

        return $hadFailure ? 1 : 0;
    }
}
