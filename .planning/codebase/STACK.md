# Technology Stack

**Analysis Date:** 2026-04-16

## Languages

**Primary:**
- PHP >=7.1.3 - All library source code under `src/`

**Secondary:**
- SQL - Procedure definition files (`database/procedures/{group}/{NAME}/current.sql`, `versions/NNN_label.sql`)

## Runtime

**Environment:**
- PHP >=7.1.3 (minimum declared in `composer.json`)
- Compatible with PHP 7.1.3 through current 8.x (no upper bound set)

**Package Manager:**
- Composer
- Lockfile: not committed (`.gitignore` excludes `composer.lock`)

## Frameworks

**Core:**
- Laravel (via `illuminate/support` ^5.8|^6.0|^7.0|^8.0) - Service container, config, facades
- `illuminate/console` ^5.8|^6.0|^7.0|^8.0 - Artisan command registration and output
- `illuminate/database` ^5.8|^6.0|^7.0|^8.0 - DB facade, query builder, migrations, Schema builder
- `illuminate/filesystem` ^5.8|^6.0|^7.0|^8.0 - Filesystem abstraction (used indirectly)

**Testing:**
- Not detected (no test runner config or test files present; `composer.json` has no `require-dev` section)

**Build/Dev:**
- None detected (no webpack, vite, or build pipeline)

## Key Dependencies

**Critical:**
- `illuminate/support` - ServiceProvider base class, config merging, facades (`config()`, `database_path()`, `config_path()`)
- `illuminate/database` - `DB::connection()->unprepared($sql)`, query builder via `DB::table()`, `Schema::create()` in migration stub, `DB::connection()->select()`
- `illuminate/console` - `Illuminate\Console\Command` base for all five Artisan commands

**Infrastructure:**
- None beyond the illuminate/* packages listed above

## Configuration

**Environment:**
- No `.env` file in this package (it is a library; the consuming Laravel app provides environment)
- Config published to consuming app via `php artisan vendor:publish --tag=procedure-config`
- Config file: `config/procedure.php` — merged under the `procedure` key at registration

**Key config keys:**
- `procedure.base_path` — root directory for procedure SQL files (default: `database_path('procedures')`)
- `procedure.history_table` — table name for version history (default: `procedure_versions`)
- `procedure.snapshot_on_apply` — auto-snapshot on apply (default: `true`)
- `procedure.default_snapshot_message` — fallback snapshot label (default: `auto_snapshot`)
- `procedure.version_padding` — zero-padding width for version filenames (default: `3`)
- `procedure.sql.strip_trailing_oracle_slash` — strip Oracle `/` terminator (default: `true`)
- `procedure.sql.remove_mysql_delimiter` — strip MySQL `DELIMITER` directives (default: `true`)
- `procedure.auto_group.*` — heuristic settings for `procedure:dump` auto-grouping

**Build:**
- No build step required; pure PHP library loaded via Composer PSR-4 autoload
- Autoload namespace: `Alncris2\LaravelProcedure\` → `src/`

## Platform Requirements

**Development:**
- PHP >=7.1.3
- Composer
- A Laravel application host (>=5.8) to run commands against

**Production:**
- Deployed as a Composer dependency inside a Laravel application
- No standalone deployment; executes within the host app's process and DB connection
- Database driver must be one of: `oracle`, `mysql`, `pgsql`, or `sqlsrv` for full feature support
  - `procedure:dump` (reading procedures from DB) supports only `oracle` and `mysql`
  - `procedure:apply` / `procedure:rollback` work for all four drivers

---

*Stack analysis: 2026-04-16*
