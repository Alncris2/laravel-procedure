<?php

namespace Alncri2\LaravelProcedure\Console\Commands;

use Alncri2\LaravelProcedure\Contracts\ProcedureExecutorInterface;
use Illuminate\Console\Command;

class MakeProcedureCommand extends Command
{
    protected $signature = 'procedure:make {group : Grupo/domínio da procedure} {name : Nome da procedure}';

    protected $description = 'Cria a estrutura de diretórios e current.sql para uma nova procedure.';

    public function handle(ProcedureExecutorInterface $executor)
    {
        $group = (string) $this->argument('group');
        $name = (string) $this->argument('name');

        $basePath = rtrim((string) config('procedure.base_path'), '/\\');
        $procedurePath = $basePath . DIRECTORY_SEPARATOR . $group . DIRECTORY_SEPARATOR . $name;
        $versionsPath = $procedurePath . DIRECTORY_SEPARATOR . 'versions';
        $currentPath = $procedurePath . DIRECTORY_SEPARATOR . 'current.sql';

        if (!is_dir($versionsPath)) {
            if (!@mkdir($versionsPath, 0775, true) && !is_dir($versionsPath)) {
                $this->error('Não foi possível criar o diretório: ' . $versionsPath);
                return 1;
            }
        }

        if (is_file($currentPath)) {
            $this->warn('current.sql já existe: ' . $currentPath);
        } else {
            $template = $executor->makeTemplate($name);
            if (file_put_contents($currentPath, $template) === false) {
                $this->error('Falha ao gravar current.sql em ' . $currentPath);
                return 1;
            }
            $this->info('Criado: ' . $currentPath);
        }

        $this->info('Estrutura pronta em: ' . $procedurePath);
        return 0;
    }
}
