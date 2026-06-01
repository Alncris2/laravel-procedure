# Technical Concerns & Known Issues

**Severity Levels:** Critical (blocks production use), High (data integrity risk), Medium (operational friction), Low (minor/cosmetic)

---

## Critical Issues

### 1. Version Number Collision After Dump

**Severity:** Critical  
**Impact:** Unique constraint violation on apply; procedure marked failed; manual recovery required

**Problem:**
- `SnapshotService::createFromCurrent()` counts disk files in `versions/` to determine the next version number
- `ProcedureDumpService::dumpAll()` writes baseline rows to `procedure_versions` table without a corresponding disk file
- On the next apply after dump, `SnapshotService` may assign the same version number already used for the baseline

**Example:**
```
1. Dump creates baseline: procedure_versions has (group='acc', name='sp_report', version=1)
2. Next apply: SnapshotService looks at disk files (0 files in versions/), assigns version=1
3. storeAppliedVersion() hits unique constraint (group_name, procedure_name, version_number)
```

**Mitigation:** Must query `ProcedureVersionRepository::getNextVersionNumber()` to seed the disk-based counter, not just count files.

---

### 2. Checksum Mismatch Between Dump and Status

**Severity:** High  
**Impact:** Procedures always show CHANGED after dump import; false positives in drift detection

**Problem:**
- `ProcedureDumpService` normalizes SQL before storing the checksum in `procedure_versions.checksum`
- `ProcedureStatusService` reads the raw `current.sql` file and compares against the stored checksum without normalization
- Normalization strips Oracle `/ ` and MySQL `DELIMITER` directives — if the dumped source contains these, the checksums won't match

**Example:**
```
Dumped SQL (stored in DB):
  CREATE PROCEDURE sp_report AS ... /
  Checksum = sha256(normalized_without_trailing_slash)

current.sql (on disk):
  CREATE PROCEDURE sp_report AS ... /
  Status checksum = sha256(raw_with_trailing_slash)
  
Result: Checksum mismatch → CHANGED status (false positive)
```

**Mitigation:** `ProcedureStatusService` must normalize `current.sql` before checksum comparison.

---

### 3. No Transaction Wrapping in Apply

**Severity:** High  
**Impact:** Silent re-application on partial failure; inconsistent database state

**Problem:**
- `ProcedureApplyService::apply()` executes SQL, then writes the record to `procedure_versions`
- If the record write fails (constraint violation, permission denied), the SQL was committed but the history entry was not
- On the next apply, the procedure is re-applied (status is PENDING or CHANGED)

**Mitigation:** Wrap SQL execution and record insert in a transaction. If either fails, roll back both.

---

## High-Priority Issues

### 4. Rollback Does Not Update `current.sql`

**Severity:** High  
**Impact:** Persistent CHANGED status after rollback; confuses subsequent operations

**Problem:**
- `ProcedureRollbackService` re-executes the target snapshot's SQL and marks it `is_current=true` in the database
- It does NOT update the `current.sql` file on disk to match the rolled-back version
- Next status check sees: file checksum (old) != database checksum (target snapshot) → CHANGED

**Mitigation:** After successful rollback, write the target snapshot contents to `current.sql`.

---

### 5. Procedure Path Traversal Risk

**Severity:** High  
**Impact:** Local file creation outside intended `base_path`; security vulnerability

**Problem:**
- `procedure:make {group} {name}` accepts group and name as command arguments
- `MakeProcedureCommand` constructs paths using string concatenation: `{base_path}/{group}/{name}/`
- No sanitization of `..` sequences or absolute paths

**Example:**
```bash
php artisan procedure:make ../../../etc group/name
# Creates: /etc/group/name/current.sql
```

**Mitigation:** Validate group and name to alphanumeric + `_` only. Reject `..`, `/`, and absolute paths.

---

### 6. Absolute File Paths in Database

**Severity:** High  
**Impact:** Data loss or corruption on host migration; stale paths in backup restores

**Problem:**
- `procedure_versions.file_path` stores the absolute path to snapshot files
- On host migration or restore-to-different-path, all paths become invalid
- Rollback attempts to read from stale paths, fails silently or crashes

**Example:**
```
Original host: /var/www/laravel/database/procedures/...
Restored host: /home/user/project/database/procedures/...
Database still has: /var/www/laravel/database/procedures/.../001_initial.sql
→ Rollback fails; procedure is now unrecoverable
```

**Mitigation:** Store relative paths or reconstruct paths at runtime using `procedure.base_path` + relative path.

---

## Medium-Priority Issues

### 7. Laravel Version Ceiling Too Low

**Severity:** Medium  
**Impact:** Cannot be used on Laravel 9, 10, 11; blocks upgrade path for consumers

**Problem:**
- `composer.json` specifies `illuminate/*: ^8.0`
- Laravel 9+ maintains BC with ^8.0 API, but package cannot be installed

**Mitigation:** Update version constraint to `^5.8 || ^6.0 || ^7.0 || ^8.0 || ^9.0 || ^10.0 || ^11.0` (or use `*` if no breaking changes are anticipated).

---

### 8. PHP Minimum Version Too Low

**Severity:** Medium  
**Impact:** Allows installation on EOL PHP versions; blocks use of modern language features

**Problem:**
- `composer.json` specifies `php: >=7.1.3`
- PHP 7.1 reached EOL on December 1, 2019; 7.2 on November 30, 2020
- Security updates no longer available

**Mitigation:** Increase minimum to `>=7.4` or `>=8.0` depending on target deployment environments.

---

### 9. No Automated Test Suite

**Severity:** Medium  
**Impact:** Regressions undetected; deployment risk increases with each change

**Problem:**
- Zero tests in `tests/` directory
- No test dependencies in `composer.json`
- No CI/CD test step
- Manual testing only

**Mitigation:** Implement PHPUnit test suite (unit + integration + feature tests); add CI/CD step.

---

## Low-Priority Issues

### 10. Dump Not Supported on PostgreSQL and SQL Server

**Severity:** Low  
**Impact:** `procedure:dump` command throws exception on pgsql/sqlsrv; workaround is manual creation

**Problem:**
- `DefaultProcedureSourceReader` only implements Oracle and MySQL source readers
- PostgreSQL and SQL Server users cannot use `procedure:dump`

**Mitigation:** Implement readers for `pgsql` (via `information_schema.routines`) and `sqlsrv` (via `sys.procedures`, `sys.sql_modules`).

---

### 11. Absolute vs. Relative Path Inconsistency

**Severity:** Low  
**Impact:** Confusion when debugging; file_path column not useful across environments

**Problem:**
- `ProcedureDefinition.basePath`, `currentPath`, `versionsPath` are absolute
- `file_path` stored in database is also absolute
- Only the `file_name` (`001_label.sql`) is environment-agnostic

**Mitigation:** Store relative paths or add a helper to reconstruct paths.

---

## Areas Requiring Caution

### Auto-Group Resolver Heuristics

The `AutoGroupResolver` cascade (prefix → tables → schema → fallback) may produce unexpected grouping on edge cases:

- **Many-to-many table relationships** — may misclassify procedures if they share tables with unrelated procedures
- **Naming collisions** — two unrelated prefixes that hash to the same group
- **Schema drift** — if owner/schema information is incomplete

**Recommendation:** Always review auto-group output with `--dry-run` before applying.

---

### Baseline Rows and Versioning

Baseline rows created by `procedure:dump` have:
- `version_label = 'dump_import'`
- No corresponding file in `versions/` directory
- Special handling required in rollback (skipped with message)

Operations that assume all history has a disk file may fail on baseline-only procedures.

---

## Recommendations (Priority Order)

| Issue | Effort | Impact | Priority |
|---|---|---|---|
| Fix version collision after dump | 1–2 hours | Critical | **P0** |
| Fix checksum normalization in status | 30 min | High | **P0** |
| Add transaction wrapping | 1 hour | High | **P0** |
| Sanitize group/name in procedure:make | 30 min | High | **P1** |
| Update current.sql on rollback | 1 hour | High | **P1** |
| Store relative paths instead of absolute | 2–3 hours | High | **P1** |
| Update Laravel version constraint | 15 min | Medium | **P2** |
| Increase PHP minimum | 15 min | Medium | **P2** |
| Implement test suite | 4–6 hours | Medium | **P2** |
| Add pgsql/sqlsrv dump support | 3–4 hours | Low | **P3** |
