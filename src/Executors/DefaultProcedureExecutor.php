<?php

namespace Alncris2\LaravelProcedure\Executors;

use Alncris2\LaravelProcedure\Contracts\ProcedureExecutorInterface;
use Alncris2\LaravelProcedure\Support\SqlNormalizer;
use Exception;
use Illuminate\Support\Facades\DB;

class DefaultProcedureExecutor implements ProcedureExecutorInterface
{
    /**
     * @return string
     */
    public function driver()
    {
        return DB::connection()->getDriverName();
    }

    /**
     * @param string $sql
     * @return array
     */
    public function execute($sql)
    {
        $sql = $this->normalize($sql);

        $start = microtime(true);
        try {
            DB::connection()->unprepared($sql);
            $ms = (int) round((microtime(true) - $start) * 1000);
            return array(
                'status' => 'success',
                'execution_time_ms' => $ms,
                'error_message' => null,
            );
        } catch (Exception $e) {
            $ms = (int) round((microtime(true) - $start) * 1000);
            return array(
                'status' => 'failed',
                'execution_time_ms' => $ms,
                'error_message' => $e->getMessage(),
            );
        }
    }

    /**
     * @param string $sql
     * @return string
     */
    public function normalize($sql)
    {
        $options = config('procedure.sql', array());
        return SqlNormalizer::normalize($sql, $this->driver(), $options);
    }

    /**
     * @param string $procedureName
     * @return string
     */
    public function makeTemplate($procedureName)
    {
        $driver = $this->driver();

        if ($driver === 'oracle') {
            return "CREATE OR REPLACE PROCEDURE {$procedureName} AS\nBEGIN\n    NULL;\nEND;\n";
        }

        if ($driver === 'mysql') {
            return "DROP PROCEDURE IF EXISTS {$procedureName};\n\n"
                . "CREATE PROCEDURE {$procedureName}()\nBEGIN\n    -- TODO\n    SELECT 1;\nEND;\n";
        }

        if ($driver === 'pgsql') {
            return "CREATE OR REPLACE PROCEDURE {$procedureName}()\nLANGUAGE plpgsql\nAS \$\$\nBEGIN\n    -- TODO\nEND;\n\$\$;\n";
        }

        if ($driver === 'sqlsrv') {
            return "IF OBJECT_ID('{$procedureName}', 'P') IS NOT NULL DROP PROCEDURE {$procedureName};\nGO\n\n"
                . "CREATE PROCEDURE {$procedureName} AS\nBEGIN\n    SET NOCOUNT ON;\n    -- TODO\nEND;\n";
        }

        return "-- procedure {$procedureName}\n-- driver: {$driver}\n";
    }
}
