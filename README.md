# laravel-procedure

Versionamento e aplicação de **stored procedures** para projetos Laravel, com snapshots em disco e histórico em banco.

- Laravel 5.8+ / PHP 7.1.3+
- Usa a **connection default** do Laravel (`config('database.default')`)
- Estrutura por grupo: `database/procedures/{grupo}/{PROCEDURE}/current.sql` + `versions/YYYYMMdd_HHmmss_label.sql`
- `current.sql` é a fonte viva editada pelo dev; os arquivos em `versions/` são snapshots criados explicitamente com `procedure:version`

## Instalação

```bash
composer require alncris2/laravel-procedure
```

Publicar config e migration:

```bash
php artisan vendor:publish --tag=procedure
php artisan migrate
```

Ou separadamente:

```bash
php artisan vendor:publish --tag=procedure-config
php artisan vendor:publish --tag=procedure-migrations
```

---

## Fluxo de trabalho principal

```
procedure:dump  →  edite current.sql  →  procedure:version  →  procedure:apply
```

| Comando | Responsabilidade |
| --- | --- |
| `procedure:dump` | Puxa o SQL real do banco para `current.sql` |
| `procedure:status` | Mostra o estado de cada procedure (SYNCED / CHANGED / PENDING / FAILED) |
| `procedure:version` | Cria snapshots em `versions/` — **sem tocar no banco** |
| `procedure:apply` | Executa `current.sql` no banco — **sem criar snapshots** |
| `procedure:rollback` | Reverte para uma versão anterior |
| `procedure:make` | Cria a estrutura de diretórios + `current.sql` para uma procedure nova |

---

## Comandos

### `procedure:make` — criar procedure nova

```bash
php artisan procedure:make atendimento PRC_BUSCAR_ATENDIMENTOS
```

Gera:

```
database/procedures/atendimento/PRC_BUSCAR_ATENDIMENTOS/
  current.sql    ← template compatível com o driver detectado
  versions/
```

Edite `current.sql`. **Nunca** edite arquivos em `versions/` — são snapshots congelados.

---

### `procedure:status` — ver estado

```bash
php artisan procedure:status
php artisan procedure:status --group=atendimento
php artisan procedure:status --changed          # mostra apenas as não-sincronizadas
```

Estados possíveis:

| Status | Significado |
| --- | --- |
| `SYNCED` | `current.sql` idêntico ao que foi aplicado |
| `CHANGED` | `current.sql` diferente da versão aplicada |
| `PENDING` | `current.sql` existe mas nunca foi aplicado |
| `FAILED` | Última execução falhou |
| `UNTRACKED` | Sem `current.sql` e sem histórico |

---

### `procedure:version` — criar snapshot

Cria o arquivo de versão em `versions/` a partir do `current.sql` atual. **Não executa nada no banco.**

```bash
php artisan procedure:version --group=atendimento --message="corrige filtro de status"
php artisan procedure:version --only=PRC_BUSCAR_ATENDIMENTOS --message="add parametro data"
php artisan procedure:version   # todas as procedures com CHANGED ou PENDING
```

O snapshot gerado fica em `versions/YYYYMMdd_HHmmss_corrige_filtro_de_status.sql`.

Só cria snapshot para procedures com status `CHANGED` ou `PENDING` — procedures `SYNCED` são ignoradas.

---

### `procedure:apply` — aplicar no banco

Executa `current.sql` no banco de dados. **Não cria snapshots** — use `procedure:version` para isso antes.

```bash
php artisan procedure:apply
php artisan procedure:apply --group=atendimento
php artisan procedure:apply --only=PRC_BUSCAR_ATENDIMENTOS
php artisan procedure:apply --message="hotfix"   # label gravado no histórico quando não há snapshot prévio
```

O apply:
1. Detecta o que mudou (compara checksum de `current.sql` com o registrado em `procedure_versions`).
2. Executa o SQL via `DB::connection()->unprepared(...)`.
3. Registra o resultado em `procedure_versions`. Se um snapshot criado por `procedure:version` tiver o mesmo checksum que `current.sql`, o apply o referencia no histórico automaticamente.
4. Marca `is_current` no registro se a execução for bem-sucedida.

> **Recomendado:** rode `procedure:version` antes do `procedure:apply` para ter rastreabilidade completa e poder fazer rollback pelo arquivo de snapshot.

---

### `procedure:dump` — puxar alterações do banco

Lê o SQL real das procedures diretamente do banco e atualiza `current.sql` no código.

```bash
# Sem --group: infere grupos automaticamente (dry-run — nada é gravado)
php artisan procedure:dump

# Efetiva a proposta do auto-group
php artisan procedure:dump --apply

# Grupo explícito
php artisan procedure:dump --group=atendimento
php artisan procedure:dump --group=atendimento --only=PRC_BUSCAR_ATENDIMENTOS
php artisan procedure:dump --group=atendimento --owner=MYSCHEMA      # Oracle

# Inspecionar sem registrar versão no banco
php artisan procedure:dump --group=atendimento --no-register

# Estratégia de auto-group específica
php artisan procedure:dump --apply --strategy=prefix   # cascade|prefix|tables|schema
```

#### `--no-register` — inspecionar sem comprometer histórico

Use quando quiser puxar o que há de diferente no banco para revisar antes de versionar:

```bash
# 1. Puxa SQL real do banco para current.sql, sem registrar nada
php artisan procedure:dump --group=atendimento --no-register

# 2. Revise os arquivos: descarte as alterações que não são suas (git checkout -- current.sql)
# 3. Versione apenas o que é seu
php artisan procedure:version --group=atendimento --message="minha feature"

# 4. Aplique no banco
php artisan procedure:apply --group=atendimento
```

O dump **compara o SQL real do banco** com o `current.sql` em disco — não depende de checksums armazenados.

#### Comportamento por procedure

| Situação | Resultado |
| --- | --- |
| Primeira importação (sem `current.sql`) | Cria `current.sql` + baseline `dump_import` em `procedure_versions` |
| Banco == disco | `synced` — nenhuma escrita |
| Banco diverge do disco | Sobrescreve `current.sql` + cria `versions/YYYYMMdd_HHmmss_dump_sync.sql` |

> Com `--no-register` nenhuma linha é gravada em `procedure_versions` em nenhum caso.

#### Auto-group

Quando `--group` é omitido, o grupo é inferido por cascata determinística:

1. **Prefixo do nome** — `SP_INV_*`, `PRC_FIN_*` → agrupa pelo token de domínio, ignorando prefixos de tipo (`sp_`, `prc_`, etc.).
2. **Tabelas referenciadas** — parse leve de `FROM`/`JOIN`/`UPDATE`/`INSERT INTO`; procedures que compartilham tabelas caem no mesmo grupo.
3. **Owner/schema** do banco.
4. **`ungrouped`** como último recurso.

Configurável no bloco `auto_group` do `config/procedure.php`.

---

### `procedure:rollback` — reverter versão

```bash
php artisan procedure:rollback --only=PRC_BUSCAR_ATENDIMENTOS
php artisan procedure:rollback --only=PRC_BUSCAR_ATENDIMENTOS --to-version=2
php artisan procedure:rollback --group=atendimento
```

Reaplica o snapshot alvo completo (full-state). `--to-version=N` refere-se à posição do snapshot na lista ordenada por nome (mais antigo = 1).

> Rollback requer que o snapshot físico exista em `versions/`. Baselines de dump (primeira importação) não têm snapshot físico e não podem ser alvo de rollback.

---

## Fluxo com dois devs na mesma procedure

### Ponto de partida

Ambos partem da mesma `current.sql` na branch `prd`:

```sql
-- SP_USUARIOS/current.sql
CREATE OR REPLACE PROCEDURE SP_USUARIOS AS
BEGIN
  SELECT * FROM usuarios;
END;
```

### Dev A — adiciona filtro

```bash
git checkout -b feature/filtro-ativo prd
```

```sql
-- Edita current.sql
SELECT * FROM usuarios WHERE ativo = 1;
```

```bash
php artisan procedure:version --message="filtro ativo"
# Gera: versions/20260601_090000_filtro_ativo.sql

php artisan procedure:apply
git add .
git commit -m "feat: SP_USUARIOS filtra apenas usuários ativos"
```

### Dev B — adiciona ordenação (em paralelo)

```bash
git checkout -b fix/ordenacao prd
```

```sql
-- Edita current.sql
SELECT * FROM usuarios ORDER BY nome;
```

```bash
php artisan procedure:version --message="ordenacao por nome"
# Gera: versions/20260601_103000_ordenacao_por_nome.sql

php artisan procedure:apply
git add .
git commit -m "fix: SP_USUARIOS retorna usuários ordenados por nome"
```

### Merge

O PR do Dev A entra primeiro. Quando o PR do Dev B vai ser mergeado:

| Arquivo | Resultado no git |
| --- | --- |
| `versions/20260601_103000_ordenacao_por_nome.sql` | **Sem conflito** — timestamp diferente, arquivo único |
| `current.sql` | **Conflito** — A tem `WHERE ativo = 1`, B tem `ORDER BY nome` |

Quem faz o merge resolve o conflito em `current.sql` unindo as duas alterações:

```sql
SELECT * FROM usuarios WHERE ativo = 1 ORDER BY nome;
```

### Deploy (CI/CD)

```bash
php artisan procedure:version --message="merge filtro e ordenacao"
php artisan procedure:apply
```

`procedure:status` detecta `CHANGED` — `current.sql` mergeado tem checksum diferente do snapshot individual de cada dev. O apply executa o SQL combinado no banco.

**Snapshots não conflitam** porque o nome inclui o timestamp do momento em que foram gerados — Dev A gerou às 09:00, Dev B às 10:30, nomes distintos, git os trata como dois arquivos novos independentes.

---

## Configuração

`config/procedure.php`:

```php
return [
    'base_path'                => database_path('procedures'),
    'history_table'            => 'procedure_versions',
    'default_snapshot_message' => 'auto_snapshot',
    'max_snapshots'            => 5,
    'sql' => [
        'strip_trailing_oracle_slash' => true,
        'remove_mysql_delimiter'      => true,
    ],
    'auto_group' => [
        'min_cluster_size' => 2,
        'prefix_separator' => '_',
        'noise_prefixes'   => ['sp', 'usp', 'prc', 'proc', 'fn', 'fnc', 'p'],
        'noise_tables'     => ['dual'],
        'fallback'         => 'ungrouped',
    ],
];
```

### Opções principais

| Opção | Padrão | Descrição |
| --- | --- | --- |
| `base_path` | `database/procedures` | Raiz da estrutura de procedures |
| `history_table` | `procedure_versions` | Tabela de histórico no banco |
| `default_snapshot_message` | `auto_snapshot` | Label do snapshot quando `--message` não é passado |
| `max_snapshots` | `5` | Máximo de snapshots por procedure em disco. O mais antigo é removido quando excedido. Use `0` para desativar. |

---

## Tabela de histórico — `procedure_versions`

| Coluna | Descrição |
| --- | --- |
| `group_name`, `procedure_name` | Localização lógica |
| `version_number`, `version_label`, `file_name`, `file_path` | Rastreabilidade do snapshot |
| `checksum` | SHA-256 do conteúdo aplicado |
| `execution_status` | `success` ou `failed` |
| `execution_time_ms`, `error_message` | Métricas e erro da execução |
| `applied_at`, `rolled_back_at` | Auditoria temporal |
| `is_current` | Flag da versão atualmente ativa por procedure |

---

## Filosofia

- **Separação clara de responsabilidades**: `procedure:version` gerencia arquivos, `procedure:apply` executa no banco.
- **Full-state, não patch**: cada snapshot contém o SQL completo. Rollback simples, auditoria simples.
- **Git como fonte da verdade**: `current.sql` é o que o time edita e mergeia. O banco segue o git.
- **Dump inspecionável**: `--no-register` permite puxar o estado real do banco para revisar e selecionar o que versionar.
- **Sem conflito de snapshots em paralelo**: nomes baseados em timestamp eliminam colisões quando múltiplos devs trabalham na mesma procedure.

## Licença

MIT
