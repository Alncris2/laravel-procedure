# laravel-procedure

Versionamento e aplicação de **stored procedures** para projetos Laravel, com snapshots automáticos em disco e histórico em banco.

- Laravel 5.8+ / PHP 7.1.3+
- Usa sempre a **connection default** do Laravel (`config('database.default')`)
- Estrutura por grupo: `database/procedures/{grupo}/{PROCEDURE}/current.sql` + `versions/NNN_label.sql`
- `current.sql` é a fonte viva editada pelo dev; os snapshots em `versions/` são **gerados automaticamente**

## Instalação

```bash
composer require alncri2/laravel-procedure
```

Publicar config e migration:

```bash
php artisan vendor:publish --tag=procedure-config
php artisan vendor:publish --tag=procedure-migrations
php artisan migrate
```

## Uso

### Criar uma procedure

```bash
php artisan procedure:make atendimento PRC_BUSCAR_ATENDIMENTOS
```

Gera:

```
database/procedures/atendimento/PRC_BUSCAR_ATENDIMENTOS/
  current.sql
  versions/
```

O `current.sql` vem com um template compatível com o driver detectado (oracle, mysql, pgsql, sqlsrv).

### Editar

Abra e edite `current.sql`. **Nunca** edite os arquivos em `versions/` — eles são snapshots congelados.

### Ver status

```bash
php artisan procedure:status
php artisan procedure:status --group=atendimento
php artisan procedure:status --changed
```

Estados possíveis: `SYNCED`, `CHANGED`, `PENDING`, `FAILED`, `UNTRACKED`.

### Aplicar

```bash
php artisan procedure:apply
php artisan procedure:apply --only=PRC_BUSCAR_ATENDIMENTOS --message="corrige filtro de status"
php artisan procedure:apply --group=atendimento
```

O apply:
1. Detecta o que mudou comparando o checksum do `current.sql` com a versão marcada como `is_current` no banco.
2. Cria automaticamente um snapshot em `versions/NNN_slug.sql` (se `snapshot_on_apply` estiver habilitado).
3. Executa o SQL com `DB::connection()->unprepared(...)`.
4. Registra o histórico em `procedure_versions`.

### Dump (importar procedures do banco)

Para trazer procedures já existentes no banco para dentro do projeto:

```bash
php artisan procedure:dump --group=imported
php artisan procedure:dump --group=atendimento --only=PRC_BUSCAR_ATENDIMENTOS
php artisan procedure:dump --group=atendimento --owner=MYSCHEMA          # Oracle
php artisan procedure:dump --group=imported --no-register                # não grava linha em procedure_versions
```

Comportamento por procedure:
- Se a estrutura local **não existe** → cria `current.sql` com o SQL do banco e gera `versions/001_dump_import.sql`.
- Se existe e o checksum **bate** com o banco → marca como `synced` e não faz nada.
- Se existe e **diverge** → sobrescreve o `current.sql` com o SQL do banco e cria um novo snapshot `NNN_dump_import.sql` (preservando o histórico anterior em `versions/`).

Suporta Oracle (`USER_SOURCE` / `ALL_SOURCE`) e MySQL (`SHOW CREATE PROCEDURE`).

### Rollback

```bash
php artisan procedure:rollback --only=PRC_BUSCAR_ATENDIMENTOS
php artisan procedure:rollback --only=PRC_BUSCAR_ATENDIMENTOS --to-version=1
php artisan procedure:rollback --group=atendimento
```

Reaplica o snapshot alvo completo (full-state) e marca o `is_current` no registro correspondente.

## Configuração

`config/procedure.php`:

```php
return [
    'base_path' => database_path('procedures'),
    'history_table' => 'procedure_versions',
    'snapshot_on_apply' => true,
    'default_snapshot_message' => 'auto_snapshot',
    'version_padding' => 3,
    'sql' => [
        'strip_trailing_oracle_slash' => true,
        'remove_mysql_delimiter' => true,
    ],
];
```

## Tabela de histórico

`procedure_versions`:

| Coluna | Descrição |
| --- | --- |
| `group_name`, `procedure_name` | Localização lógica |
| `version_number`, `version_label`, `file_name`, `file_path` | Rastreabilidade do snapshot |
| `checksum` | sha256 do conteúdo aplicado |
| `execution_status` | `success` ou `failed` |
| `execution_time_ms`, `error_message` | Métricas/erro da execução |
| `applied_at`, `rolled_back_at` | Auditoria |
| `is_current` | Flag da versão atualmente ativa |

## Filosofia

- **Full-state, não patch**: cada snapshot contém o SQL completo da procedure.
- **Idempotência no DDL**: escreva `CREATE OR REPLACE` / `DROP ... IF EXISTS` conforme o driver.
- **Uma fonte viva**: `current.sql` é o único arquivo editável; o resto é histórico.

## Licença

MIT
