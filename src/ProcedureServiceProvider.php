<?php

namespace Alncris2\LaravelProcedure;

use Alncris2\LaravelProcedure\Console\Commands\ApplyProcedureCommand;
use Alncris2\LaravelProcedure\Console\Commands\DumpProcedureCommand;
use Alncris2\LaravelProcedure\Console\Commands\MakeProcedureCommand;
use Alncris2\LaravelProcedure\Console\Commands\RollbackProcedureCommand;
use Alncris2\LaravelProcedure\Console\Commands\StatusProcedureCommand;
use Alncris2\LaravelProcedure\Contracts\ProcedureExecutorInterface;
use Alncris2\LaravelProcedure\Contracts\ProcedureSourceReaderInterface;
use Alncris2\LaravelProcedure\Executors\DefaultProcedureExecutor;
use Alncris2\LaravelProcedure\Readers\DefaultProcedureSourceReader;
use Alncris2\LaravelProcedure\Repositories\ProcedureVersionRepository;
use Alncris2\LaravelProcedure\Services\ProcedureApplyService;
use Alncris2\LaravelProcedure\Services\ProcedureDumpService;
use Alncris2\LaravelProcedure\Services\ProcedureRollbackService;
use Alncris2\LaravelProcedure\Services\ProcedureScanner;
use Alncris2\LaravelProcedure\Services\ProcedureStatusService;
use Alncris2\LaravelProcedure\Services\SnapshotService;
use Illuminate\Support\ServiceProvider;

class ProcedureServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/procedure.php', 'procedure');

        $this->app->singleton(ProcedureExecutorInterface::class, function () {
            return new DefaultProcedureExecutor();
        });

        $this->app->singleton(ProcedureSourceReaderInterface::class, function () {
            return new DefaultProcedureSourceReader();
        });

        $this->app->singleton(ProcedureScanner::class, function () {
            return new ProcedureScanner();
        });

        $this->app->singleton(ProcedureVersionRepository::class, function () {
            return new ProcedureVersionRepository();
        });

        $this->app->singleton(SnapshotService::class, function () {
            return new SnapshotService();
        });

        $this->app->singleton(ProcedureStatusService::class, function ($app) {
            return new ProcedureStatusService(
                $app->make(ProcedureScanner::class),
                $app->make(ProcedureVersionRepository::class)
            );
        });

        $this->app->singleton(ProcedureApplyService::class, function ($app) {
            return new ProcedureApplyService(
                $app->make(ProcedureScanner::class),
                $app->make(SnapshotService::class),
                $app->make(ProcedureExecutorInterface::class),
                $app->make(ProcedureVersionRepository::class),
                $app->make(ProcedureStatusService::class)
            );
        });

        $this->app->singleton(ProcedureRollbackService::class, function ($app) {
            return new ProcedureRollbackService(
                $app->make(ProcedureScanner::class),
                $app->make(ProcedureExecutorInterface::class),
                $app->make(ProcedureVersionRepository::class)
            );
        });

        $this->app->singleton(ProcedureDumpService::class, function ($app) {
            return new ProcedureDumpService(
                $app->make(ProcedureSourceReaderInterface::class),
                $app->make(ProcedureScanner::class),
                $app->make(SnapshotService::class),
                $app->make(ProcedureExecutorInterface::class),
                $app->make(ProcedureVersionRepository::class)
            );
        });
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $configPath = __DIR__ . '/../config/procedure.php';
            $migrationPath = __DIR__ . '/Migrations/create_procedure_versions_table.php.stub';
            $migrationTarget = database_path(
                'migrations/' . date('Y_m_d_His') . '_create_procedure_versions_table.php'
            );

            // Tag individual: só o config.
            $this->publishes(array(
                $configPath => config_path('procedure.php'),
            ), 'procedure-config');

            // Tag individual: só a migration.
            $this->publishes(array(
                $migrationPath => $migrationTarget,
            ), 'procedure-migrations');

            // Tag guarda-chuva: publica tudo de uma vez.
            //     php artisan vendor:publish --tag=procedure
            $this->publishes(array(
                $configPath => config_path('procedure.php'),
                $migrationPath => $migrationTarget,
            ), 'procedure');

            $this->commands(array(
                MakeProcedureCommand::class,
                StatusProcedureCommand::class,
                ApplyProcedureCommand::class,
                RollbackProcedureCommand::class,
                DumpProcedureCommand::class,
            ));
        }
    }
}
