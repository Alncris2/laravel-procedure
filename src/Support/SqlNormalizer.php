<?php

namespace Alncris2\LaravelProcedure\Support;

class SqlNormalizer
{
    /**
     * Normaliza o conteúdo do SQL de uma procedure.
     *
     * @param string      $sql
     * @param string|null $driver
     * @param array       $options
     * @return string
     */
    public static function normalize($sql, $driver = null, array $options = array())
    {
        $sql = str_replace(array("\r\n", "\r"), "\n", (string) $sql);
        $sql = trim($sql);

        $stripOracleSlash = isset($options['strip_trailing_oracle_slash'])
            ? (bool) $options['strip_trailing_oracle_slash']
            : true;

        $removeMysqlDelimiter = isset($options['remove_mysql_delimiter'])
            ? (bool) $options['remove_mysql_delimiter']
            : true;

        if ($driver === 'oracle' && $stripOracleSlash) {
            // Remove "/" final comum em scripts Oracle quando enviado via unprepared.
            $sql = preg_replace('/\n\s*\/\s*$/', '', $sql);
            $sql = rtrim($sql);
            if (substr($sql, -1) === '/') {
                $sql = rtrim(substr($sql, 0, -1));
            }
        }

        if ($driver === 'mysql' && $removeMysqlDelimiter) {
            // Remove linhas DELIMITER ... (caso o usuário tenha colado).
            $sql = preg_replace('/^\s*DELIMITER\s+\S+\s*$/mi', '', $sql);
            $sql = trim($sql);
        }

        return $sql;
    }
}
