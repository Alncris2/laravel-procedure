# Architecture

**Package:** alncris2/laravel-procedure
**Namespace:** `Alncris2\LaravelProcedure\`
**Type:** Laravel service-provider library (Composer, PSR-4)

---

## Overview

`laravel-procedure` is a versioning and execution manager for database stored procedures inside a Laravel application. It mirrors the mental model of Laravel migrations — every procedure has a `current.sql` (the working copy) and a timestamped `versions/` directory of immutable snapshots. The package tracks every applied snapshot in a dedicated history table and exposes five Artisan commands as the public interface.

The package has no HTTP layer and no runtime dependencies beyond four `illuminate/*` packages (support, console, database, filesystem). It works against Oracle, MySQL, PostgreSQL, and SQL Server; `procedure:dump` (importing from the live database) is limited to Oracle and MySQL.

---

## Layers

```
Console Commands  (src/Console/Commands/)
        |
        v
   Services       (src/Services/)
    |       |
    v       v
Repository    Contracts / Implementations
(src/Repositories/)   (src/Contracts/ + src/Executors/ + src/Readers/)
        |
        v
   Models         (src/Models/)
   Support        (src/Support/)
```

Each layer depends only on the layer below it. Commands depend on services; services depend on the repository, contracts, and models; nothing below services knows about commands.

---

## Entry Point

**`ProcedureServiceProvider`** (`src/ProcedureServiceProvider.php`)

Registered automatically via `composer.json` `extra.laravel.providers`. On `register()` it binds every class as a singleton in the service container:

| Binding | Concrete |
|---|---|
| `ProcedureExecutorInterface` | `DefaultProcedureExecutor` |
| `ProcedureSourceReaderInterface` | `DefaultProcedureSourceReader` |
| `ProcedureScanner` | `ProcedureScanner` |
| `ProcedureVersionRepository` | `ProcedureVersionRepository` |
| `SnapshotService` | `SnapshotService` |
| `ProcedureStatusService` | `ProcedureStatusService` |
| `ProcedureApplyService` | `ProcedureApplyService` |
| `ProcedureRollbackService` | `ProcedureRollbackService` |
| `AutoGroupResolver` | `AutoGroupResolver` (configured from `procedure.auto_group`) |
| `ProcedureDumpService` | `ProcedureDumpService` |

On `boot()` it publishes config and migration stub under three tags (`procedure-config`, `procedure-migrations`, `procedure`) and registers all five Artisan commands.

---

## Contracts (Interfaces)

### `ProcedureExecutorInterface`

```
execute($sql)       -> array { status, execution_time_ms, error_message }
normalize($sql)     -> string
makeTemplate($name) -> string
driver()            -> string
```

Implemented by `DefaultProcedureExecutor`. Executes SQL via `DB::connection()->unprepared()`. `normalize()` delegates to `SqlNormalizer`. `makeTemplate()` returns driver-specific boilerplate for Oracle, MySQL, PostgreSQL, and SQL Server.

### `ProcedureSourceReaderInterface`

```
listProcedures(array $options)         -> string[]
listProceduresDetailed(array $options) -> array[]{name, owner}
getProcedureSource($name, $options)    -> string
driver()                               -> string
supportsDump()                         -> bool
```

Implemented by `DefaultProcedureSourceReader`. Queries catalog views for Oracle (`USER_OBJECTS` / `ALL_OBJECTS`, `USER_SOURCE` / `ALL_SOURCE`) and MySQL (`information_schema.ROUTINES`, `SHOW CREATE PROCEDURE`). Throws `RuntimeException` for unsupported drivers. `supportsDump()` returns true only for `oracle` and `mysql`.

---

## Services

### `ProcedureScanner`

Reads the on-disk procedure tree rooted at `procedure.base_path`. Walks `{base_path}/{group}/{name}/` and builds `ProcedureDefinition` value objects. Reads `versions/NNN_label.sql` files into `ProcedureSnapshot` value objects, sorted by version number. Exposes `all()`, `findByGroup()`, `findByName()`, and `buildDefinition()`.

### `SnapshotService`

Creates a physical snapshot file: copies `current.sql` to `versions/NNN_label.sql` where `NNN` is zero-padded to `procedure.version_padding` digits and `label` is the slugified `--message` (or `procedure.default_snapshot_message`). Version numbering is based purely on the highest existing snapshot file in `versions/`; it does not consult the database.

### `ProcedureStatusService`

Computes one of five statuses for every `ProcedureDefinition` by comparing the SHA-256 checksum of `current.sql` against the checksum stored in the most recent `is_current = true` row in `procedure_versions`:

| Status | Condition |
|---|---|
| `SYNCED` | current checksum equals applied checksum |
| `CHANGED` | current checksum differs from applied checksum |
| `PENDING` | `current.sql` exists but no applied row |
| `FAILED` | last execution was `failed` |
| `UNTRACKED` | no `current.sql` and no history |

### `ProcedureApplyService`

Orchestrates the apply flow for one, all, or a group of procedures:
1. Calls `ProcedureStatusService.statusFor()` — skips if `SYNCED`.
2. If `procedure.snapshot_on_apply` is true, calls `SnapshotService.createFromCurrent()` to write the versioned file.
3. Calls `ProcedureExecutorInterface.execute()`.
4. Calls `ProcedureVersionRepository.storeAppliedVersion()` then `markCurrent()` on success.

### `ProcedureRollbackService`

Reverts a procedure to the previous snapshot (or a specified version):
1. Loads the current applied row from the repository.
2. Finds the target `ProcedureSnapshot` from `ProcedureDefinition.snapshots` (disk files only — baseline-only rows cannot be rolled back).
3. Calls `ProcedureExecutorInterface.execute()` with the snapshot SQL.
4. Calls `repository.markRolledBack()` on the former current row, then `markCurrent()` on the target row (creating a new row if no existing record matches).

Baseline import rows (label `dump_import`, no physical file in `versions/`) are detected and return a descriptive skip message.

### `ProcedureDumpService`

Imports procedures from the live database into the on-disk structure. Three outcomes per procedure:

| Outcome | Condition | Files written | DB row |
|---|---|---|---|
| `created` | `current.sql` does not exist | `current.sql` | Baseline row (`dump_import`, `is_current = true`, no file in `versions/`) |
| `synced` | `current.sql` checksum matches DB source | none | none |
| `updated` | `current.sql` checksum differs | `current.sql` rewritten + `versions/NNN_dump_sync.sql` | `dump_sync` row, `is_current = true` |

When `--group` is omitted, delegates to `AutoGroupResolver` to infer a group for each procedure. Without `--apply`, runs as a dry-run and prints only the proposed grouping.

### `AutoGroupResolver`

Pure (no IO) resolver. Accepts a list of `{name, owner, source}` maps and returns a group assignment for each procedure using a four-step cascade:

1. **Prefix** — tokenises the name (splitting on `_`, handling camelCase), skips noise prefixes (`sp`, `usp`, `prc`, `proc`, `fn`, `fnc`, `p`), uses the first meaningful token as a group candidate if at least `min_cluster_size` procedures share it.
2. **Tables** — extracts table names from SQL source (regex over `FROM`, `JOIN`, `UPDATE`, `INSERT INTO`, `DELETE FROM`, `MERGE INTO`); unions procedures that share tables via union-find; names the component by the majority-voted table.
3. **Schema** — uses the `owner` field returned by the reader.
4. **Fallback** — assigns the `procedure.auto_group.fallback` value (default: `ungrouped`).

Can be invoked with a specific strategy (`prefix`, `tables`, `schema`) or the default `cascade`.

---

## Repository

### `ProcedureVersionRepository`

Thin query-builder wrapper over the `procedure_versions` table (name from `procedure.history_table`). Key operations:

- `getCurrentApplied($group, $name)` — finds the `is_current = true` row.
- `getLatestVersion($group, $name)` — highest `version_number` regardless of status.
- `getNextVersionNumber($group, $name)` — `max(version_number) + 1`; used by `ProcedureDumpService` to keep file numbering consistent with the DB-side baseline history.
- `storeAppliedVersion(array $data)` — inserts a new row, returns the inserted id.
- `markCurrent($group, $name, $id)` — atomically clears existing `is_current` flags and sets the new one.
- `markRolledBack($id)` — sets `rolled_back_at` and clears `is_current`.

---

## Models (Value Objects)

### `ProcedureDefinition`

Plain PHP object. Properties: `group`, `name`, `basePath`, `currentPath`, `versionsPath`, `snapshots[]`. Methods: `hasCurrent()`, `readCurrent()`, `latestSnapshot()`. No Eloquent; not persisted.

### `ProcedureSnapshot`

Plain PHP object. Properties: `versionNumber`, `label`, `fileName`, `fullPath`, `contents`, `checksum`. Represents one file in `versions/`. No Eloquent; not persisted.

---

## Support Utilities

### `SqlNormalizer`

Static. Normalises line endings. Driver-specific:
- **oracle** — removes trailing `/` (PL/SQL block terminator rejected by PDO).
- **mysql** — removes `DELIMITER //` / `DELIMITER ;` lines (client-side directives rejected by PDO).

Controlled by `procedure.sql.strip_trailing_oracle_slash` and `procedure.sql.remove_mysql_delimiter`.

### `Checksum`

Static. `hash($contents)` returns `sha256` hex string.

### `Slugger`

Static. `slug($message, $fallback)` lowercases, transliterates Latin characters (via `iconv` if available), replaces non-alphanumeric runs with `_`, trims underscores, and truncates to 60 characters.

---

## Database Schema (`procedure_versions`)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | auto-increment |
| `group_name` | string | maps to on-disk group directory |
| `procedure_name` | string | procedure name in the database |
| `version_number` | uint | sequential per procedure |
| `version_label` | string nullable | slug from snapshot filename |
| `file_name` | string | snapshot filename (`NNN_label.sql`) |
| `file_path` | string | absolute path to snapshot file |
| `checksum` | char(64) | SHA-256 of normalised SQL |
| `execution_status` | varchar(20) | `success` or `failed` |
| `execution_time_ms` | uint nullable | PHP-measured execution time |
| `error_message` | text nullable | exception message on failure |
| `applied_at` | timestamp nullable | when execution completed |
| `rolled_back_at` | timestamp nullable | when this version was superseded |
| `is_current` | boolean | at most one true per (group, name) |
| `created_at` / `updated_at` | timestamps | standard Laravel audit |

Unique constraint: `(group_name, procedure_name, version_number)`. Indices on `(group_name, procedure_name)`, `(procedure_name, version_number)`, `is_current`.

---

## Artisan Commands

| Command | Class | Key Options |
|---|---|---|
| `procedure:make` | `MakeProcedureCommand` | `{group}` `{name}` |
| `procedure:status` | `StatusProcedureCommand` | `--group=` `--changed` |
| `procedure:apply` | `ApplyProcedureCommand` | `--only=` `--group=` `--message=` |
| `procedure:rollback` | `RollbackProcedureCommand` | `--only=` `--group=` `--to-version=` |
| `procedure:dump` | `DumpProcedureCommand` | `--group=` `--only=` `--owner=` `--no-register` `--apply` `--strategy=` |

`procedure:dump` without `--group` runs as a dry-run (prints proposed grouping table); add `--apply` to execute. Add `--group=NAME` to force a single explicit group.

---

## Extension Points

Both contracts (`ProcedureExecutorInterface`, `ProcedureSourceReaderInterface`) are registered as singletons via the service provider. To replace either implementation, rebind the interface in the application's own service provider:

```php
$this->app->singleton(
    ProcedureExecutorInterface::class,
    MyCustomExecutor::class
);
```

No static calls, no `new` inside constructors that accept injected dependencies — all wiring is in `ProcedureServiceProvider::register()`.
