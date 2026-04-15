<?php

namespace Alncris2\LaravelProcedure\Readers;

use Alncris2\LaravelProcedure\Contracts\ProcedureSourceReaderInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DefaultProcedureSourceReader implements ProcedureSourceReaderInterface
{
    /**
     * @return string
     */
    public function driver()
    {
        return DB::connection()->getDriverName();
    }

    /**
     * @return bool
     */
    public function supportsDump()
    {
        $driver = $this->driver();
        return $driver === 'oracle' || $driver === 'mysql';
    }

    /**
     * @param array $options
     * @return string[]
     */
    public function listProcedures(array $options = array())
    {
        $driver = $this->driver();

        if ($driver === 'oracle') {
            return $this->listOracle($options);
        }
        if ($driver === 'mysql') {
            return $this->listMysql($options);
        }

        throw new RuntimeException(
            'Dump de procedures não suportado para o driver: ' . $driver
        );
    }

    /**
     * @param array $options
     * @return array<int, array{name: string, owner: ?string}>
     */
    public function listProceduresDetailed(array $options = array())
    {
        $driver = $this->driver();

        if ($driver === 'oracle') {
            return $this->listOracleDetailed($options);
        }
        if ($driver === 'mysql') {
            return $this->listMysqlDetailed($options);
        }

        throw new RuntimeException(
            'Dump de procedures não suportado para o driver: ' . $driver
        );
    }

    /**
     * @param string $name
     * @param array  $options
     * @return string
     */
    public function getProcedureSource($name, array $options = array())
    {
        $driver = $this->driver();

        if ($driver === 'oracle') {
            return $this->getOracleSource($name, $options);
        }
        if ($driver === 'mysql') {
            return $this->getMysqlSource($name, $options);
        }

        throw new RuntimeException(
            'Dump de procedures não suportado para o driver: ' . $driver
        );
    }

    // ---------------------------------------------------------------------
    // Oracle
    // ---------------------------------------------------------------------

    /**
     * @param array $options
     * @return string[]
     */
    protected function listOracle(array $options)
    {
        $only = isset($options['only']) ? $options['only'] : null;
        $owner = isset($options['owner']) ? $options['owner'] : null;

        if ($owner) {
            $sql = 'SELECT DISTINCT OBJECT_NAME AS NAME FROM ALL_OBJECTS '
                 . "WHERE OBJECT_TYPE = 'PROCEDURE' AND OWNER = ?";
            $bindings = array(strtoupper($owner));
            if ($only) {
                $sql .= ' AND OBJECT_NAME = ?';
                $bindings[] = strtoupper($only);
            }
            $sql .= ' ORDER BY OBJECT_NAME';
        } else {
            $sql = 'SELECT DISTINCT OBJECT_NAME AS NAME FROM USER_OBJECTS '
                 . "WHERE OBJECT_TYPE = 'PROCEDURE'";
            $bindings = array();
            if ($only) {
                $sql .= ' AND OBJECT_NAME = ?';
                $bindings[] = strtoupper($only);
            }
            $sql .= ' ORDER BY OBJECT_NAME';
        }

        $rows = DB::connection()->select($sql, $bindings);
        $out = array();
        foreach ($rows as $row) {
            $row = (array) $row;
            $name = isset($row['NAME']) ? $row['NAME'] : (isset($row['name']) ? $row['name'] : null);
            if ($name !== null) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * @param array $options
     * @return array<int, array{name: string, owner: ?string}>
     */
    protected function listOracleDetailed(array $options)
    {
        $only = isset($options['only']) ? $options['only'] : null;
        $owner = isset($options['owner']) ? $options['owner'] : null;

        if ($owner) {
            $sql = 'SELECT DISTINCT OBJECT_NAME AS NAME, OWNER AS OWNER FROM ALL_OBJECTS '
                 . "WHERE OBJECT_TYPE = 'PROCEDURE' AND OWNER = ?";
            $bindings = array(strtoupper($owner));
            if ($only) {
                $sql .= ' AND OBJECT_NAME = ?';
                $bindings[] = strtoupper($only);
            }
            $sql .= ' ORDER BY OBJECT_NAME';
        } else {
            $sql = 'SELECT DISTINCT OBJECT_NAME AS NAME, USER AS OWNER FROM USER_OBJECTS '
                 . "WHERE OBJECT_TYPE = 'PROCEDURE'";
            $bindings = array();
            if ($only) {
                $sql .= ' AND OBJECT_NAME = ?';
                $bindings[] = strtoupper($only);
            }
            $sql .= ' ORDER BY OBJECT_NAME';
        }

        $rows = DB::connection()->select($sql, $bindings);
        $out = array();
        foreach ($rows as $row) {
            $row = (array) $row;
            $name = isset($row['NAME']) ? $row['NAME'] : (isset($row['name']) ? $row['name'] : null);
            $own = isset($row['OWNER']) ? $row['OWNER'] : (isset($row['owner']) ? $row['owner'] : null);
            if ($name !== null) {
                $out[] = array('name' => $name, 'owner' => $own);
            }
        }
        return $out;
    }

    /**
     * @param string $name
     * @param array  $options
     * @return string
     */
    protected function getOracleSource($name, array $options)
    {
        $owner = isset($options['owner']) ? $options['owner'] : null;
        $upper = strtoupper($name);

        if ($owner) {
            $sql = 'SELECT TEXT FROM ALL_SOURCE '
                 . "WHERE TYPE = 'PROCEDURE' AND OWNER = ? AND NAME = ? ORDER BY LINE";
            $bindings = array(strtoupper($owner), $upper);
        } else {
            $sql = 'SELECT TEXT FROM USER_SOURCE '
                 . "WHERE TYPE = 'PROCEDURE' AND NAME = ? ORDER BY LINE";
            $bindings = array($upper);
        }

        $rows = DB::connection()->select($sql, $bindings);
        if (empty($rows)) {
            throw new RuntimeException('Procedure não encontrada no Oracle: ' . $name);
        }

        $body = '';
        foreach ($rows as $row) {
            $row = (array) $row;
            $text = isset($row['TEXT']) ? $row['TEXT'] : (isset($row['text']) ? $row['text'] : '');
            $body .= $text;
        }

        $body = ltrim($body);

        // USER_SOURCE começa com "PROCEDURE nome ..." — prefixar CREATE OR REPLACE.
        // Se por acaso já vier com CREATE, respeita.
        if (!preg_match('/^\s*CREATE\s+/i', $body)) {
            $body = "CREATE OR REPLACE " . $body;
        }

        $body = rtrim($body);
        if (substr($body, -1) !== ';') {
            $body .= ';';
        }

        return $body . "\n";
    }

    // ---------------------------------------------------------------------
    // MySQL
    // ---------------------------------------------------------------------

    /**
     * @param array $options
     * @return string[]
     */
    protected function listMysql(array $options)
    {
        $only = isset($options['only']) ? $options['only'] : null;

        $sql = 'SELECT ROUTINE_NAME AS name FROM information_schema.ROUTINES '
             . "WHERE ROUTINE_TYPE = 'PROCEDURE' AND ROUTINE_SCHEMA = DATABASE()";
        $bindings = array();
        if ($only) {
            $sql .= ' AND ROUTINE_NAME = ?';
            $bindings[] = $only;
        }
        $sql .= ' ORDER BY ROUTINE_NAME';

        $rows = DB::connection()->select($sql, $bindings);
        $out = array();
        foreach ($rows as $row) {
            $row = (array) $row;
            if (isset($row['name']) && $row['name'] !== null) {
                $out[] = $row['name'];
            }
        }
        return $out;
    }

    /**
     * @param array $options
     * @return array<int, array{name: string, owner: ?string}>
     */
    protected function listMysqlDetailed(array $options)
    {
        $only = isset($options['only']) ? $options['only'] : null;

        $sql = 'SELECT ROUTINE_NAME AS name, ROUTINE_SCHEMA AS owner FROM information_schema.ROUTINES '
             . "WHERE ROUTINE_TYPE = 'PROCEDURE' AND ROUTINE_SCHEMA = DATABASE()";
        $bindings = array();
        if ($only) {
            $sql .= ' AND ROUTINE_NAME = ?';
            $bindings[] = $only;
        }
        $sql .= ' ORDER BY ROUTINE_NAME';

        $rows = DB::connection()->select($sql, $bindings);
        $out = array();
        foreach ($rows as $row) {
            $row = (array) $row;
            if (isset($row['name']) && $row['name'] !== null) {
                $out[] = array(
                    'name' => $row['name'],
                    'owner' => isset($row['owner']) ? $row['owner'] : null,
                );
            }
        }
        return $out;
    }

    /**
     * @param string $name
     * @param array  $options
     * @return string
     */
    protected function getMysqlSource($name, array $options)
    {
        $rows = DB::connection()->select('SHOW CREATE PROCEDURE ' . $this->quoteMysqlName($name));
        if (empty($rows)) {
            throw new RuntimeException('Procedure não encontrada no MySQL: ' . $name);
        }
        $row = (array) $rows[0];

        $create = null;
        foreach ($row as $key => $value) {
            if (strcasecmp($key, 'Create Procedure') === 0) {
                $create = $value;
                break;
            }
        }
        if ($create === null || $create === '') {
            throw new RuntimeException('SHOW CREATE PROCEDURE retornou vazio para: ' . $name);
        }

        $sql = "DROP PROCEDURE IF EXISTS `" . str_replace('`', '', $name) . "`;\n\n"
             . rtrim($create);

        if (substr($sql, -1) !== ';') {
            $sql .= ';';
        }
        return $sql . "\n";
    }

    /**
     * @param string $name
     * @return string
     */
    protected function quoteMysqlName($name)
    {
        $name = str_replace('`', '', $name);
        return '`' . $name . '`';
    }
}
