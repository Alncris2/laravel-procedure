<?php

namespace Alncris2\LaravelProcedure\Console\Commands;

use Alncris2\LaravelProcedure\Services\ProcedureDumpService;
use Illuminate\Console\Command;

class DumpProcedureCommand extends Command
{
    protected $signature = 'procedure:dump
                            {--group= : Grupo de destino. Se omitido, executa auto-group em modo dry-run}
                            {--only= : Apenas uma procedure pelo nome}
                            {--owner= : Owner/schema (oracle)}
                            {--no-register : Não registra a importação em procedure_versions}
                            {--apply : Efetiva a proposta de auto-group (requer --group ausente)}
                            {--strategy=cascade : Heurística de auto-group: cascade|prefix|tables|schema}';

    protected $description = 'Importa todas as procedures do banco, criando/atualizando current.sql e gerando snapshot com label dump_import. Sem --group, infere grupos automaticamente (dry-run por padrão).';

    public function handle(ProcedureDumpService $dump)
    {
        $group = $this->option('group');
        $only = $this->option('only');
        $owner = $this->option('owner');
        $register = !$this->option('no-register');
        $apply = (bool) $this->option('apply');
        $strategy = (string) $this->option('strategy');

        $options = array(
            'only' => $only,
            'owner' => $owner,
            'register' => $register,
            'strategy' => $strategy,
        );

        // Sem grupo explícito: auto-group.
        if ($group === null || $group === '') {
            if (!$apply) {
                return $this->handleAutoGroupDryRun($dump, $options);
            }
            $results = $dump->dumpAll(null, $options);
            return $this->renderResults($results);
        }

        if ($apply) {
            $this->warn('A flag --apply é ignorada quando --group é informado explicitamente.');
        }

        $results = $dump->dumpAll((string) $group, $options);
        return $this->renderResults($results);
    }

    /**
     * @return int
     */
    protected function handleAutoGroupDryRun(ProcedureDumpService $dump, array $options)
    {
        $plan = $dump->planAutoGroup($options);

        if (empty($plan)) {
            $this->info('Nenhuma procedure encontrada no banco.');
            return 0;
        }

        $rows = array();
        foreach ($plan as $item) {
            $tables = $item['tables'];
            $tablesStr = empty($tables) ? '' : implode(', ', array_slice($tables, 0, 4))
                . (count($tables) > 4 ? ', …' : '');
            $rows[] = array(
                'procedure' => $item['name'],
                'grupo_proposto' => $item['group'],
                'estrategia' => $item['strategy'],
                'tabelas' => $tablesStr,
            );
        }

        $this->info('Proposta de auto-group (dry-run). Nada foi gravado.');
        $this->table(
            array('procedure', 'grupo_proposto', 'estrategia', 'tabelas'),
            $rows
        );
        $this->line('');
        $this->line('Para efetivar: php artisan procedure:dump --apply');
        $this->line('Para forçar grupo único: php artisan procedure:dump --group=NOME');
        return 0;
    }

    /**
     * @return int
     */
    protected function renderResults(array $results)
    {
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
