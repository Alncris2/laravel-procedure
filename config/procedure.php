<?php

return array(

    /*
    |--------------------------------------------------------------------------
    | Base Path
    |--------------------------------------------------------------------------
    |
    | Diretório raiz onde ficam as procedures do projeto. Dentro dele é
    | esperada a estrutura:
    |
    |     {base_path}/{grupo}/{PROCEDURE_NAME}/current.sql
    |     {base_path}/{grupo}/{PROCEDURE_NAME}/versions/NNN_label.sql
    |
    | O valor pode ser um caminho absoluto ou qualquer caminho resolvível
    | pelo helper database_path(). Os comandos procedure:make, procedure:apply,
    | procedure:status, procedure:rollback e procedure:dump usam este caminho
    | como única fonte de verdade — não há pastas separadas por conexão.
    |
    */

    'base_path' => database_path('procedures'),

    /*
    |--------------------------------------------------------------------------
    | History Table
    |--------------------------------------------------------------------------
    |
    | Nome da tabela usada para armazenar o histórico de versões aplicadas,
    | checksums, tempos de execução e mensagens de erro. Essa tabela é criada
    | pela migration publicada via:
    |
    |     php artisan vendor:publish --tag=procedure-migrations
    |     php artisan migrate
    |
    | Se você mudar este valor após a migration original ter rodado, será
    | necessário renomear a tabela manualmente ou gerar uma nova migration.
    |
    */

    'history_table' => 'procedure_versions',

    /*
    |--------------------------------------------------------------------------
    | Snapshot On Apply
    |--------------------------------------------------------------------------
    |
    | Quando verdadeiro, o comando procedure:apply copia automaticamente o
    | conteúdo do current.sql para versions/NNN_label.sql antes de executar
    | o SQL no banco. Esse é o comportamento recomendado — garante que toda
    | versão aplicada tenha um snapshot full-state em disco, permitindo
    | rollback e auditoria.
    |
    | Defina como false apenas em pipelines onde você gerencia os snapshots
    | manualmente (por exemplo, via CI que commita versions/* antes do apply).
    |
    */

    'snapshot_on_apply' => true,

    /*
    |--------------------------------------------------------------------------
    | Default Snapshot Message
    |--------------------------------------------------------------------------
    |
    | Label usado no nome do arquivo de snapshot quando o usuário não passa
    | --message no procedure:apply. O valor é "slugificado" automaticamente:
    | acentos são removidos, espaços viram underscore, caracteres especiais
    | são descartados. O arquivo resultante tem o formato:
    |
    |     versions/NNN_{slug_da_mensagem}.sql
    |
    | Exemplo: "corrige filtro de status" vira "corrige_filtro_de_status".
    |
    */

    'default_snapshot_message' => 'auto_snapshot',

    /*
    |--------------------------------------------------------------------------
    | Version Padding
    |--------------------------------------------------------------------------
    |
    | Quantidade de dígitos usada no prefixo numérico dos arquivos de snapshot.
    | O valor padrão (3) produz nomes como "001_initial.sql", "042_fix.sql".
    |
    | Aumente se você espera gerar mais de 999 versões de uma mesma procedure.
    | Diminuir depois que o projeto já tem snapshots existentes é seguro — o
    | parser aceita qualquer quantidade de dígitos; o padding só afeta os
    | novos arquivos gerados a partir daqui.
    |
    */

    'version_padding' => 3,

    /*
    |--------------------------------------------------------------------------
    | SQL Normalization
    |--------------------------------------------------------------------------
    |
    | Ajustes aplicados ao conteúdo SQL antes de executar no banco e antes
    | de calcular checksums. Essas opções existem para acomodar sintaxes de
    | cliente que não são aceitas quando o SQL é enviado via PDO::exec /
    | unprepared().
    |
    | strip_trailing_oracle_slash
    |     Remove o caractere "/" final usado pelo SQL*Plus para encerrar
    |     blocos PL/SQL. O PDO do Oracle não aceita esse terminador e
    |     retornaria ORA-00911. Mantenha true a menos que você tenha um
    |     driver customizado que já trate isso.
    |
    | remove_mysql_delimiter
    |     Remove linhas "DELIMITER //" e "DELIMITER ;" que são comuns em
    |     dumps gerados pelo mysqldump ou MySQL Workbench. Essas linhas são
    |     diretivas do cliente mysql, não comandos SQL, e fazem o driver PDO
    |     falhar com um erro de sintaxe.
    |
    */

    'sql' => array(

        'strip_trailing_oracle_slash' => true,

        'remove_mysql_delimiter' => true,

    ),

    /*
    |--------------------------------------------------------------------------
    | Auto Group (procedure:dump sem --group)
    |--------------------------------------------------------------------------
    |
    | Quando o comando procedure:dump é executado sem --group, o grupo de
    | destino de cada procedure é inferido por uma cascata de heurísticas
    | determinísticas:
    |
    |   1. Prefixo do nome (SP_INV_* / PRC_FIN_* / UpdateCustomer)
    |   2. Tabelas referenciadas no corpo (clustering por tabelas compartilhadas)
    |   3. Owner/schema do banco
    |   4. Fallback literal
    |
    | min_cluster_size   Mínimo de procedures num candidato a grupo para o
    |                    prefixo/clustering valer. Abaixo disso, cai pro
    |                    próximo passo da cascata.
    |
    | prefix_separator   Separador usado para quebrar o nome em tokens.
    |
    | noise_prefixes     Tokens de "tipo" que devem ser pulados ao escolher
    |                    o prefixo (ex.: SP_INV_UPDATE → usa "INV", não "SP").
    |
    | noise_tables       Nomes de tabelas a ignorar no clustering por tabela
    |                    (além de qualquer "temp_*" / "tmp_*", já ignorados).
    |
    | fallback           Grupo usado quando nenhuma heurística se aplica.
    |
    */

    'auto_group' => array(
        'min_cluster_size' => 2,
        'prefix_separator' => '_',
        'noise_prefixes' => array('sp', 'usp', 'prc', 'proc', 'fn', 'fnc', 'p'),
        'noise_tables' => array('dual'),
        'fallback' => 'ungrouped',
    ),

);
