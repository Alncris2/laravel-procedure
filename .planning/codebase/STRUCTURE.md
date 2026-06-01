# Project Structure

```
laravel-procedure/
├── composer.json
├── LICENSE
├── README.md
│
├── config/
│   └── procedure.php               # Default config (published to consuming app)
│
└── src/
    ├── ProcedureServiceProvider.php
    │
    ├── Console/
    │   └── Commands/
    │       ├── ApplyProcedureCommand.php
    │       ├── DumpProcedureCommand.php
    │       ├── MakeProcedureCommand.php
    │       ├── RollbackProcedureCommand.php
    │       └── StatusProcedureCommand.php
    │
    ├── Contracts/
    │   ├── ProcedureExecutorInterface.php
    │   └── ProcedureSourceReaderInterface.php
    │
    ├── Executors/
    │   └── DefaultProcedureExecutor.php
    │
    ├── Migrations/
    │   └── create_procedure_versions_table.php.stub
    │
    ├── Models/
    │   ├── ProcedureDefinition.php
    │   └── ProcedureSnapshot.php
    │
    ├── Readers/
    │   └── DefaultProcedureSourceReader.php
    │
    ├── Repositories/
    │   └── ProcedureVersionRepository.php
    │
    ├── Services/
    │   ├── AutoGroupResolver.php
    │   ├── ProcedureApplyService.php
    │   ├── ProcedureDumpService.php
    │   ├── ProcedureRollbackService.php
    │   ├── ProcedureScanner.php
    │   ├── ProcedureStatusService.php
    │   └── SnapshotService.php
    │
    └── Support/
        ├── Checksum.php
        ├── Slugger.php
        └── SqlNormalizer.php
```

---

## File-by-File Reference

### Root

| File | Purpose |
|---|---|
| `composer.json` | Package metadata; declares `Alncris2\LaravelProcedure\` PSR-4 autoload; registers `ProcedureServiceProvider` via `extra.laravel.providers`; requires `illuminate/{support,console,database,filesystem}` ^5.8–^8.0 and PHP >=7.1.3 |
| `config/procedure.php` | Default configuration merged under the `procedure` key. Keys: `base_path`, `history_table`, `snapshot_on_apply`, `default_snapshot_message`, `version_padding`, `sql.strip_trailing_oracle_slash`, `sql.remove_mysql_delimiter`, `auto_group.*` |

---

### `src/`

#### `ProcedureServiceProvider.php`
Extends `Illuminate\Support\ServiceProvider`. Binds all services and contracts as singletons in `register()`. In `boot()` publishes config and migration stub (tags: `procedure-config`, `procedure-migrations`, `procedure`) and registers the five Artisan commands when `runningInConsole()`.

---

#### `src/Console/Commands/`

| File | Artisan signature | Description |
|---|---|---|
| `MakeProcedureCommand.php` | `procedure:make {group} {name}` | Creates `{base_path}/{group}/{name}/versions/` and `current.sql` scaffold using a driver-specific template from `ProcedureExecutorInterface::makeTemplate()` |
| `StatusProcedureCommand.php` | `procedure:status [--group=] [--changed]` | Calls `ProcedureStatusService::getAllStatuses()` and renders a table of SYNCED / CHANGED / PENDING / FAILED / UNTRACKED rows |
| `ApplyProcedureCommand.php` | `procedure:apply [--only=] [--group=] [--message=]` | Delegates to `ProcedureApplyService::applyOne/applyGroup/applyAll`; exits with code 1 if any procedure failed |
| `RollbackProcedureCommand.php` | `procedure:rollback {--only=\|--group=} [--to-version=]` | Delegates to `ProcedureRollbackService::rollbackOne/rollbackGroup`; requires at least one of `--only` or `--group` |
| `DumpProcedureCommand.php` | `procedure:dump [--group=] [--only=] [--owner=] [--no-register] [--apply] [--strategy=cascade]` | Without `--group`: dry-run auto-group preview (calls `ProcedureDumpService::planAutoGroup`); with `--apply` or explicit `--group`: calls `ProcedureDumpService::dumpAll` |

---

#### `src/Contracts/`

| File | Interface | Methods |
|---|---|---|
| `ProcedureExecutorInterface.php` | `ProcedureExecutorInterface` | `execute($sql)`, `normalize($sql)`, `makeTemplate($name)`, `driver()` |
| `ProcedureSourceReaderInterface.php` | `ProcedureSourceReaderInterface` | `listProcedures($opts)`, `listProceduresDetailed($opts)`, `getProcedureSource($name, $opts)`, `driver()`, `supportsDump()` |

---

#### `src/Executors/`

| File | Class | Notes |
|---|---|---|
| `DefaultProcedureExecutor.php` | `DefaultProcedureExecutor` | Implements `ProcedureExecutorInterface`. Calls `DB::connection()->unprepared($sql)`. Normalises SQL via `SqlNormalizer` before execution and before checksum. Generates driver-specific templates for oracle, mysql, pgsql, sqlsrv. |

---

#### `src/Migrations/`

| File | Notes |
|---|---|
| `create_procedure_versions_table.php.stub` | Migration stub. Published to `database/migrations/` via `vendor:publish --tag=procedure-migrations`. Reads the table name from `config('procedure.history_table')`. Creates all columns, indices, and the unique constraint on `(group_name, procedure_name, version_number)`. |

---

#### `src/Models/`

Plain PHP value objects — no Eloquent, not persisted.

| File | Class | Public Properties |
|---|---|---|
| `ProcedureDefinition.php` | `ProcedureDefinition` | `group`, `name`, `basePath`, `currentPath`, `versionsPath`, `snapshots[]`; methods `hasCurrent()`, `readCurrent()`, `latestSnapshot()` |
| `ProcedureSnapshot.php` | `ProcedureSnapshot` | `versionNumber`, `label`, `fileName`, `fullPath`, `contents`, `checksum` |

---

#### `src/Readers/`

| File | Class | Notes |
|---|---|---|
| `DefaultProcedureSourceReader.php` | `DefaultProcedureSourceReader` | Implements `ProcedureSourceReaderInterface`. Oracle path: queries `USER_OBJECTS`/`ALL_OBJECTS` and `USER_SOURCE`/`ALL_SOURCE`; prefixes `CREATE OR REPLACE` if absent. MySQL path: queries `information_schema.ROUTINES` and `SHOW CREATE PROCEDURE`; prepends `DROP PROCEDURE IF EXISTS`. Throws `RuntimeException` for any other driver. |

---

#### `src/Repositories/`

| File | Class | Notes |
|---|---|---|
| `ProcedureVersionRepository.php` | `ProcedureVersionRepository` | Query-builder wrapper over `procedure_versions`. Methods: `getCurrentApplied`, `getLatestVersion`, `findVersion`, `getNextVersionNumber`, `storeAppliedVersion`, `markCurrent`, `markRolledBack`, `getHistory`. Table name read from `config('procedure.history_table')`. |

---

#### `src/Services/`

| File | Class | Responsibility |
|---|---|---|
| `ProcedureScanner.php` | `ProcedureScanner` | Traverses `base_path` and builds `ProcedureDefinition` + `ProcedureSnapshot` objects from disk |
| `SnapshotService.php` | `SnapshotService` | Creates `versions/NNN_label.sql` files from `current.sql`; version number from highest existing file in `versions/` |
| `ProcedureStatusService.php` | `ProcedureStatusService` | Compares current-file checksum vs repository checksum to produce SYNCED / CHANGED / PENDING / FAILED / UNTRACKED |
| `ProcedureApplyService.php` | `ProcedureApplyService` | Orchestrates snapshot creation + SQL execution + repository recording for apply operations |
| `ProcedureRollbackService.php` | `ProcedureRollbackService` | Finds the target snapshot, re-executes its SQL, and updates the `is_current` flag |
| `ProcedureDumpService.php` | `ProcedureDumpService` | Imports live procedures from the DB into the on-disk structure; three outcomes: `created` (baseline), `synced` (no change), `updated` (new `dump_sync` snapshot) |
| `AutoGroupResolver.php` | `AutoGroupResolver` | Pure cascade resolver (prefix → tables via union-find → schema → fallback) that maps procedure names to group strings |

---

#### `src/Support/`

| File | Class | API |
|---|---|---|
| `Checksum.php` | `Checksum` | `static hash($contents): string` — SHA-256 hex |
| `Slugger.php` | `Slugger` | `static slug($message, $fallback): string` — lowercase, iconv transliterate, non-alnum to `_`, max 60 chars |
| `SqlNormalizer.php` | `SqlNormalizer` | `static normalize($sql, $driver, $options): string` — strips trailing Oracle `/`, removes MySQL `DELIMITER` lines |

---

## On-Disk Procedure Layout (Consuming App)

After installation, procedures live under `database/procedures/` (configurable via `procedure.base_path`):

```
database/procedures/
└── {group}/                        # logical domain grouping (e.g. "faturamento")
    └── {PROCEDURE_NAME}/           # matches the procedure name in the database
        ├── current.sql             # working copy — always the latest intended state
        └── versions/
            ├── 001_initial.sql     # first snapshot
            ├── 002_corrige_filtro.sql
            └── NNN_label.sql       # each apply or dump_sync adds one file here
```

`current.sql` is the only file the developer edits. `versions/` files are written by the package and must not be edited manually.

---

## Config Reference (`config/procedure.php`)

| Key | Default | Description |
|---|---|---|
| `base_path` | `database_path('procedures')` | Root directory for all procedure SQL files |
| `history_table` | `'procedure_versions'` | Table used to store apply/rollback history |
| `snapshot_on_apply` | `true` | Auto-create `versions/NNN_*.sql` before executing on apply |
| `default_snapshot_message` | `'auto_snapshot'` | Label used when `--message` is not passed to `procedure:apply` |
| `version_padding` | `3` | Zero-pad width for version file prefix (`001`, `042`, …) |
| `sql.strip_trailing_oracle_slash` | `true` | Remove trailing `/` from Oracle PL/SQL before PDO execution |
| `sql.remove_mysql_delimiter` | `true` | Remove `DELIMITER` client directives from MySQL SQL before PDO execution |
| `auto_group.min_cluster_size` | `2` | Minimum procedures sharing a candidate group for prefix/table heuristics to activate |
| `auto_group.prefix_separator` | `'_'` | Token separator for name analysis |
| `auto_group.noise_prefixes` | `['sp','usp','prc','proc','fn','fnc','p']` | Type-prefixes skipped when extracting the meaningful group token |
| `auto_group.noise_tables` | `['dual']` | Table names ignored in clustering heuristic |
| `auto_group.fallback` | `'ungrouped'` | Group assigned when no heuristic matches |
