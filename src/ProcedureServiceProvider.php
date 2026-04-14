<?php

namespace Alncri2\LaravelProcedure;

use Alncri2\LaravelProcedure\Console\Commands\ApplyProcedureCommand;
use Alncri2\LaravelProcedure\Console\Commands\DumpProcedureCommand;
use Alncri2\LaravelProcedure\Console\Commands\MakeProcedureCommand;
use Alncri2\LaravelProcedure\Console\Commands\RollbackProcedureCommand;
use Alncri2\LaravelProcedure\Console\Commands\StatusProcedureCommand;
use Alncri2\LaravelProcedure\Contracts\ProcedureExecutorInterface;
use Alncri2\LaravelProcedure\Contracts\ProcedureSourceReaderInterface;
use Alncri2\LaravelProcedure\Executors\DefaultProcedureExecutor;
use Alncri2\LaravelProcedure\Readers\DefaultProcedureSourceReader;
use Alncri2\LaravelProcedure\Repositories\ProcedureVersionRepository;
use Alncri2\LaravelProcedure\Services\ProcedureApplyService;
use Alncri2\LaravelProcedure\Services\ProcedureDumpService;
use Alncri2\LaravelProcedure\Services\ProcedureRollbackService;
use Alncri2\LaravelProcedure\Services\ProcedureScanner;
use Alncri2\LaravelProcedure\Services\ProcedureStatusService;
use Alncri2\LaravelProcedure\Services\SnapshotService;
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
            $this->publishes(array(
                __DIR__ . '/../config/procedure.php' => config_path('procedure.php'),
            ), 'procedure-config');

            $timestamp = date('Y_m_d_His');
            $this->publishes(array(
                __DIR__ . '/Migrations/create_procedure_versions_table.php.stub' =>
                    database_path('migrations/' . $timestamp . '_create_procedure_versions_table.php'),
            ), 'procedure-migrations');

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
