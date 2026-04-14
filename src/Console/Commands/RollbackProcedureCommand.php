<?php

namespace Alncris2\LaravelProcedure\Console\Commands;

use Alncris2\LaravelProcedure\Services\ProcedureRollbackService;
use Illuminate\Console\Command;

class RollbackProcedureCommand extends Command
{
    protected $signature = 'procedure:rollback
                            {--only= : Rollback apenas desta procedure}
                            {--group= : Rollback de todas as procedures do grupo}
                            {--to-version= : Versão alvo (default = versão anterior à atual)}';

    protected $description = 'Reverte uma procedure para a versão anterior (ou uma versão específica).';

    public function handle(ProcedureRollbackService $rollback)
    {
        $only = $this->option('only');
        $group = $this->option('group');
        $target = $this->option('to-version');
        $target = ($target === null || $target === '') ? null : (int) $target;

        if (!$only && !$group) {
            $this->error('Informe --only=PROCEDURE ou --group=GRUPO.');
            return 1;
        }

        if ($only) {
            $results = $rollback->rollbackOne($only, $target);
            $results = array($results);
        } else {
            $results = $rollback->rollbackGroup($group, $target);
        }

        $rows = array();
        $hadFailure = false;
        foreach ($results as $r) {
            $action = isset($r['action']) ? $r['action'] : '';
            if ($action === 'failed') {
                $hadFailure = true;
                $this->error(sprintf(
                    '[%s] %s rollback FALHOU: %s',
                    $r['group'],
                    $r['procedure'],
                    isset($r['error_message']) ? $r['error_message'] : 'erro desconhecido'
                ));
            }
            $rows[] = array(
                'group' => $r['group'],
                'procedure' => $r['procedure'],
                'action' => $action,
                'from' => isset($r['from_version']) ? $r['from_version'] : '',
                'to' => isset($r['to_version']) ? $r['to_version'] : '',
                'note' => isset($r['reason']) ? $r['reason'] : '',
            );
        }

        $this->table(
            array('group', 'procedure', 'action', 'from', 'to', 'note'),
            $rows
        );

        return $hadFailure ? 1 : 0;
    }
}
