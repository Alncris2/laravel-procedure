<?php

namespace Alncris2\LaravelProcedure\Console\Commands;

use Alncris2\LaravelProcedure\Services\ProcedureMoveService;
use Illuminate\Console\Command;

class MoveProcedureCommand extends Command
{
    protected $signature = 'procedure:move
                            {procedures* : Nomes das procedures a mover (pode passar mais de uma)}
                            {--to= : Grupo de destino (obrigatório)}';

    protected $description = 'Move uma ou mais procedures para outro grupo, atualizando disco e histórico no banco.';

    public function handle(ProcedureMoveService $move)
    {
        $procedures = $this->argument('procedures');
        $targetGroup = $this->option('to');

        if (empty($targetGroup)) {
            $this->error('Informe o grupo de destino com --to=NOME_DO_GRUPO');
            return 1;
        }

        if (empty($procedures)) {
            $this->error('Informe ao menos o nome de uma procedure.');
            return 1;
        }

        $results = $move->moveMany($procedures, $targetGroup);

        $rows = array();
        $hadFailure = false;
        foreach ($results as $r) {
            if ($r['result'] === ProcedureMoveService::RESULT_FAILED) {
                $hadFailure = true;
                $this->error(sprintf(
                    '[%s] %s FALHOU: %s',
                    $r['from'],
                    $r['procedure'],
                    $r['reason']
                ));
            }
            $rows[] = array(
                'procedure' => $r['procedure'],
                'de' => $r['from'],
                'para' => $r['to'],
                'result' => $r['result'],
                'obs' => $r['reason'],
            );
        }

        $this->table(
            array('procedure', 'de', 'para', 'result', 'obs'),
            $rows
        );

        return $hadFailure ? 1 : 0;
    }
}
