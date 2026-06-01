# Coding Conventions

**Target:** PHP 7.1.3+
**Compatibility:** Laravel 5.8–8.0

---

## Naming Conventions

### Classes

- **PascalCase** — `ProcedureScanner`, `DefaultProcedureExecutor`, `AutoGroupResolver`
- **Interfaces** — suffix with `Interface` — `ProcedureExecutorInterface`, `ProcedureSourceReaderInterface`
- **Contracts** — stored in `src/Contracts/` directory
- **Service classes** — suffix with `Service` — `ProcedureApplyService`, `SnapshotService`
- **Repository classes** — suffix with `Repository` — `ProcedureVersionRepository`

### Methods & Properties

- **camelCase** — `getCurrentApplied()`, `storeAppliedVersion()`, `basePath`
- **Private/protected** — prefix underscore optional, not used in this codebase
- **Boolean methods** — prefix with `is`, `has`, `supports` — `hasCurrent()`, `supportsDump()`

### Constants & Configuration

- **SCREAMING_SNAKE_CASE** — not found in main code; config values are lowercase keys
- **Config keys** — dot-notation, lowercase — `procedure.base_path`, `procedure.snapshot_on_apply`

---

## Code Style

### Syntax & Formatting

- **Array syntax** — uses `array()` not `[]` — maintains PHP 5.x compatibility
- **Type hints** — PHPDoc annotations, not native type hints (PHP 5.x compatibility):
  ```php
  /**
   * @param string $name
   * @param array $options
   * @return array
   */
  public function listProcedures($name, $options)
  ```
- **Indentation** — 4 spaces
- **Line length** — no strict limit observed
- **Namespace** — `Alncris2\LaravelProcedure\*` (PSR-4 autoload from `src/`)

### Comments & Documentation

- **PHPDoc** — all public and protected methods documented:
  ```php
  /**
   * Applies a procedure and records the result.
   *
   * @param ProcedureDefinition $definition
   * @param array $options
   * @return array
   */
  public function apply(ProcedureDefinition $definition, array $options)
  ```
- **Inline comments** — written in Brazilian Portuguese (e.g., `// normaliza quebras de linha`)
- **No docstring types** — properties documented inline, rarely

---

## Module Design Patterns

### Dependency Injection

- **Constructor injection** — all dependencies passed via constructor:
  ```php
  public function __construct(
      ProcedureVersionRepository $repository,
      ProcedureExecutorInterface $executor
  ) {
      $this->repository = $repository;
      $this->executor = $executor;
  }
  ```
- **No static methods for core logic** — static methods only for pure utilities (`Checksum::hash()`, `Slugger::slug()`)
- **All bindings in ServiceProvider** — `ProcedureServiceProvider::register()` is the single source of wiring

### Return Values

- **Success cases** — return arrays with structured keys:
  ```php
  return [
      'status' => 'success',
      'execution_time_ms' => 125,
      'message' => 'Procedure applied'
  ];
  ```
- **Error cases** — throw `RuntimeException` for unrecoverable errors; capture and return in result arrays for expected failures (e.g., checksum mismatch)

### Value Objects

- **Plain PHP objects** — `ProcedureDefinition`, `ProcedureSnapshot` — no Eloquent, no persistence
- **Immutable properties** — set via constructor, treated as read-only

---

## Error Handling

- **Exceptions** — `RuntimeException` for driver unsupported, invalid config, SQL execution errors
- **Graceful degradation** — commands continue and report partial success if one procedure fails
- **Database errors** — caught and logged with context (group name, procedure name, SQL)

---

## Laravel Integration Patterns

- **Service provider** — `ProcedureServiceProvider` extends `Illuminate\Support\ServiceProvider`
- **Config publishing** — uses `publishesConfig()` with tags (`procedure-config`, `procedure-migrations`)
- **Artisan commands** — all extend `Illuminate\Console\Command`; invoked via `$this->call()` in commands that delegate to services
- **Database** — accessed via `DB::connection()->unprepared()` for raw SQL execution

---

## File Organization

- **One class per file** — matches PSR-4 autoload
- **Interfaces separated** — `Contracts/` directory
- **Support utilities** — static-only classes in `Support/` — no instantiation
- **No trait usage** — observed in codebase
