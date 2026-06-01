# External Integrations

**Analysis Date:** 2026-04-16

## APIs & External Services

None. This package has no HTTP client dependencies, no third-party API calls, and no SDK imports beyond the Laravel illuminate/* packages.

## Data Storage

**Databases:**
- **Any Laravel-configured database** (the package uses the default DB connection at runtime)
  - Connection: consumed from the host Laravel app's database config (`DB_CONNECTION`, `DB_HOST`, etc.)
  - Client: `Illuminate\Support\Facades\DB` — raw query builder via `DB::table()` and `DB::connection()->unprepared()`
  - History table: `procedure_versions` (configurable via `procedure.history_table`)
  - Migration stub: `src/Migrations/create_procedure_versions_table.php.stub`

- **Oracle (oci8 / oracle driver)**
  - Queried via `ALL_OBJECTS`, `USER_OBJECTS`, `ALL_SOURCE`, `USER_SOURCE` catalog views
  - Used by: `src/Readers/DefaultProcedureSourceReader.php` (`listOracle`, `getOracleSource`)
  - SQL normalization: strips trailing `/` (PL/SQL block terminator) before executing via PDO
  - Template generation: `CREATE OR REPLACE PROCEDURE ... AS BEGIN NULL; END;`

- **MySQL (mysql driver)**
  - Queried via `information_schema.ROUTINES` and `SHOW CREATE PROCEDURE`
  - Used by: `src/Readers/DefaultProcedureSourceReader.php` (`listMysql`, `getMysqlSource`)
  - SQL normalization: removes `DELIMITER //` / `DELIMITER ;` lines from client-tool dumps
  - Template generation: `DROP PROCEDURE IF EXISTS ...; CREATE PROCEDURE ...`

- **PostgreSQL (pgsql driver)**
  - `procedure:apply` and `procedure:rollback` work (SQL executed via `unprepared()`)
  - `procedure:dump` is NOT supported (reader throws `RuntimeException` for pgsql)
  - Template generation: `CREATE OR REPLACE PROCEDURE ... LANGUAGE plpgsql AS $$ ... $$;`

- **SQL Server (sqlsrv driver)**
  - `procedure:apply` and `procedure:rollback` work (SQL executed via `unprepared()`)
  - `procedure:dump` is NOT supported (reader throws `RuntimeException` for sqlsrv)
  - Template generation: `IF OBJECT_ID(...) IS NOT NULL DROP PROCEDURE ...; GO CREATE PROCEDURE ...`

**File Storage:**
- Local filesystem only — the package reads and writes SQL files under `procedure.base_path`
- File layout: `{base_path}/{group}/{PROCEDURE_NAME}/current.sql` and `{base_path}/{group}/{PROCEDURE_NAME}/versions/NNN_label.sql`
- All file operations use native PHP `file_get_contents`, `file_put_contents`, `mkdir`, `scandir` (no cloud storage)

**Caching:**
- None

## Authentication & Identity

**Auth Provider:**
- None (the package itself has no auth layer)
- Database authentication is delegated entirely to the host Laravel app's DB connection config

## Monitoring & Observability

**Error Tracking:**
- None (no Sentry, Bugsnag, Rollbar, etc.)

**Logs:**
- Execution errors are stored in the `error_message` column of the `procedure_versions` table
- Artisan commands output status to the console via `$this->info()`, `$this->error()`, `$this->table()`
- No PSR-3 logger integration; no `Log::` facade calls anywhere in `src/`

## CI/CD & Deployment

**Hosting:**
- Packagist / Composer — distributed as a library (`"type": "library"` in `composer.json`)
- Package name: `alncris2/laravel-procedure`

**CI Pipeline:**
- Not detected (no `.github/workflows/`, no `.gitlab-ci.yml`, no `Makefile`)

## Environment Configuration

**Required env vars:**
- None owned by this package. It inherits whatever the host Laravel app sets:
  - `DB_CONNECTION` — selects the driver (`oracle`, `mysql`, `pgsql`, `sqlsrv`)
  - Standard Laravel database env vars (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`)

**Secrets location:**
- Managed entirely by the host Laravel application; not present in this package

## Webhooks & Callbacks

**Incoming:**
- None

**Outgoing:**
- None

---

*Integration audit: 2026-04-16*
