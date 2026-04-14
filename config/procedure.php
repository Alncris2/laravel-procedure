<?php

return array(

    /*
    |--------------------------------------------------------------------------
    | Base path
    |--------------------------------------------------------------------------
    |
    | Diretório raiz onde ficam as procedures do projeto. Dentro dele é
    | esperada a estrutura: {grupo}/{PROCEDURE}/current.sql + versions/.
    |
    */
    'base_path' => database_path('procedures'),

    /*
    |--------------------------------------------------------------------------
    | History table
    |--------------------------------------------------------------------------
    |
    | Tabela que armazena o histórico de versões aplicadas.
    |
    */
    'history_table' => 'procedure_versions',

    /*
    |--------------------------------------------------------------------------
    | Snapshot on apply
    |--------------------------------------------------------------------------
    |
    | Se verdadeiro, antes de aplicar uma mudança o package cria um snapshot
    | automático em versions/NNN_label.sql a partir do current.sql.
    |
    */
    'snapshot_on_apply' => true,

    'default_snapshot_message' => 'auto_snapshot',

    'version_padding' => 3,

    'sql' => array(
        'strip_trailing_oracle_slash' => true,
        'remove_mysql_delimiter' => true,
    ),

);
