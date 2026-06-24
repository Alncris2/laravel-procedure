<?php

namespace Alncris2\LaravelProcedure\Console\Commands;

use Alncris2\LaravelProcedure\Services\ProcedureVersionService;
use Illuminate\Console\Command;

class VersionProcedureCommand extends Command
{
    protected $signature = 'procedure:version
                            {--only= : Cria snapshot apenas para uma procedure pelo nome}
                            {--group= : Cria snapshots apenas para procedures de um grupo}
                            {--message= : Mensagem usada para nomear o arquivo de snapshot gerado}';

    protected $description = 'Cria snapshots (arquivos de versão) para procedures alteradas sem tocar no banco de dados. Use procedure:apply para executar no banco.';

    public function handle(ProcedureVersionService $version)
    {
        $only = $this->option('only');
        $group = $this->option('group');
        $message = $this->option('message');

        if ($only) {
            $results = $version->versionOne($only, $message);
        } elseif ($group) {
            $results = $version->versionGroup($group, $message);
        } else {
            $results = $version->versionAll($message);
        }

        if (empty($results)) {
            $this->info('Nenhuma procedure encontrada.');
            return 0;
        }

        $rows = array();
        $hadFailure = false;
        foreach ($results as $r) {
            if ($r['action'] === 'failed') {
                $hadFailure = true;
                $this->error(sprintf(
                    '[%s] %s FALHOU: %s',
                    $r['group'],
                    $r['procedure'],
                    $r['reason']
                ));
            }
            $rows[] = array(
                'group' => $r['group'],
                'procedure' => $r['procedure'],
                'action' => $r['action'],
                'file' => $r['file'],
                'reason' => $r['reason'],
            );
        }

        $this->table(
            array('group', 'procedure', 'action', 'file', 'reason'),
            $rows
        );

        return $hadFailure ? 1 : 0;
    }
}
