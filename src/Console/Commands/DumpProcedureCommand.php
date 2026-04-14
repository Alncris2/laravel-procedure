<?php

namespace Alncris2\LaravelProcedure\Console\Commands;

use Alncris2\LaravelProcedure\Services\ProcedureDumpService;
use Illuminate\Console\Command;

class DumpProcedureCommand extends Command
{
    protected $signature = 'procedure:dump
                            {--group=imported : Grupo de destino na estrutura local}
                            {--only= : Apenas uma procedure pelo nome}
                            {--owner= : Owner/schema (oracle)}
                            {--no-register : Não registra a importação em procedure_versions}';

    protected $description = 'Importa todas as procedures do banco, criando/atualizando current.sql e gerando snapshot com label dump_import.';

    public function handle(ProcedureDumpService $dump)
    {
        $group = (string) $this->option('group');
        $only = $this->option('only');
        $owner = $this->option('owner');
        $register = !$this->option('no-register');

        $options = array(
            'only' => $only,
            'owner' => $owner,
            'register' => $register,
        );

        $results = $dump->dumpAll($group, $options);

        if (empty($results)) {
            $this->info('Nenhuma procedure encontrada no banco.');
            return 0;
        }

        $rows = array();
        $failed = false;
        foreach ($results as $r) {
            if ($r['result'] === ProcedureDumpService::RESULT_FAILED) {
                $failed = true;
                $this->error(sprintf(
                    '[%s] %s FALHOU: %s',
                    $r['group'],
                    $r['procedure'],
                    isset($r['error']) ? $r['error'] : 'erro desconhecido'
                ));
            }
            $rows[] = array(
                'group' => $r['group'],
                'procedure' => $r['procedure'],
                'result' => $r['result'],
                'version' => isset($r['version']) ? $r['version'] : '',
                'file' => isset($r['file']) ? $r['file'] : '',
            );
        }

        $this->table(
            array('group', 'procedure', 'result', 'version', 'file'),
            $rows
        );

        return $failed ? 1 : 0;
    }
}
