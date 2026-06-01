# Testing

**Test Framework Status:** None detected
**Test Files:** None
**Test Runner:** Not configured
**Coverage:** 0%

---

## Current State

The package has **zero automated tests**:

- No `tests/` directory
- No `phpunit.xml` or test configuration
- No test dependencies in `composer.json` (`require-dev` is absent)
- No test setup or CI/CD test steps observed

---

## Testable Surfaces

### Pure Functions (Unit Test Candidates)

These are excellent candidates for unit tests — they have no I/O, no database dependency, and produce deterministic output:

| Class | Methods | Inputs | Outputs |
|---|---|---|---|
| `Checksum` | `hash($contents)` | SQL string | SHA-256 hex string |
| `Slugger` | `slug($message, $fallback)` | message string | slugified string (lowercase, max 60 chars) |
| `SqlNormalizer` | `normalize($sql, $driver, $opts)` | SQL string + driver | normalized SQL string |
| `AutoGroupResolver` | `resolveGroups(array $procedures, $strategy)` | array of {name, owner, source} | array of {name → group} |

**Example test:**
```php
public function testSluggerTruncatesAndNormalizes()
{
    $slug = Slugger::slug('my_long_procedure_name_with_unicode_é', 'fallback');
    $this->assertLessThanOrEqual(60, strlen($slug));
    $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $slug);
}
```

### Integration Tests (Database Required)

These require a test database and test fixtures:

| Class | Scenario | Setup |
|---|---|---|
| `ProcedureVersionRepository` | Insert, query, update, mark current, rollback | Create `procedure_versions` table; seed test rows |
| `DefaultProcedureExecutor` | Execute SQL; normalize; handle errors | Test database connection; test procedures |
| `DefaultProcedureSourceReader` (Oracle/MySQL) | List procedures; fetch source; handle unsupported drivers | Oracle/MySQL test instance |
| `ProcedureScanner` | Traverse disk; load snapshots; detect missing files | Fixture directory structure |
| `ProcedureStatusService` | Compute status; compare checksums | Disk files + database rows with matching/diverging checksums |
| `ProcedureApplyService` | Apply single, apply group, skip synced, handle failures | Fixture procedures; test database |

**Example test:**
```php
public function testApplySkipsSyncedProcedures()
{
    // Setup: create fixture with SYNCED procedure
    // Execute: call applyAll()
    // Assert: no SQL execution for SYNCED; execution count = 0
}
```

### Commands (End-to-End Tests)

| Command | Test Case |
|---|---|
| `procedure:make` | Create group/name dir, scaffold current.sql |
| `procedure:status` | Display status table; `--changed` flag filters results |
| `procedure:apply` | Apply single with `--only=`, apply group with `--group=`, apply all |
| `procedure:rollback` | Rollback to previous version; rollback with `--to-version=` |
| `procedure:dump` | Dry-run without `--apply`; import with `--apply`; infer groups with `--strategy=` |

---

## Recommended Test Structure

```
tests/
├── Unit/
│   ├── Support/
│   │   ├── ChecksumTest.php
│   │   ├── SluggerTest.php
│   │   └── SqlNormalizerTest.php
│   └── AutoGroupResolverTest.php
│
├── Integration/
│   ├── ProcedureVersionRepositoryTest.php
│   ├── DefaultProcedureExecutorTest.php
│   ├── ProcedureScannerTest.php
│   ├── ProcedureStatusServiceTest.php
│   └── ProcedureApplyServiceTest.php
│
└── Feature/
    ├── Commands/
    │   ├── MakeProcedureCommandTest.php
    │   ├── StatusProcedureCommandTest.php
    │   ├── ApplyProcedureCommandTest.php
    │   ├── RollbackProcedureCommandTest.php
    │   └── DumpProcedureCommandTest.php
    └── Fixtures/
        └── procedures/           # Test procedure files
```

---

## Coverage Goals

- **Unit (100%)** — all pure functions in `Support/` and `AutoGroupResolver`
- **Integration (80%+)** — Repository, Executor, Scanner, Services
- **Feature (70%+)** — All Artisan commands with happy path + error cases
- **Critical paths** — Apply, rollback, dump (version collisions, checksum mismatches)

---

## Notes for Implementation

- Use **PHPUnit 9.x** (compatible with Laravel 5.8–8.0)
- Use **Laravel Testing Traits** — `RefreshDatabase`, `CreatesApplication` (if applicable)
- Use **Database transactions** — wrap each test in a transaction to avoid test pollution
- **Fixture procedures** — store minimal SQL snippets in `tests/Fixtures/`
- **Mock the executor** in some tests to avoid real DB connections; use real DB in integration tests
