# Apply Progress: Project Foundation Bootstrap

## Previous Attempt: Environment Blocker

- Change: `project-foundation-bootstrap`
- Mode: Standard (`strict_tdd: false`)
- Slice: PR 1 — reproducible runtime
- Chain strategy: stacked-to-main
- Attempt outcome: failed
- Evidence revision: `sha256:b43d003e2d074258b2b2b683c946574c8aca5228da3ebefd1eb3363d5e0f6acd`
- Harness disposition: invalidated

## Previous Attempt Task Progress

No tasks are complete. Tasks 1.1–1.7 remain pending because Composer availability and therefore package compatibility, a committed lock, and the required executable harness could not be proven.

## Proven Diagnosis

- `php`, `composer`, and `mysql` are not available on `PATH`; each name-based probe returned `CommandNotFoundException`.
- PHP is locally available at `C:\xampp\php\php.exe`. `--version` returned PHP 7.4.20 and exit 0.
- The direct PHP module probe returned exit 0 and included PDO, `pdo_mysql`, `mbstring`, OpenSSL, DOM, XML, XMLWriter, and ZIP.
- The MariaDB client is locally available at `C:\xampp\mysql\bin\mysql.exe`. `--version` returned MariaDB 10.4.19 and exit 0.
- Composer was not found on `PATH` or in the bounded common candidates checked under XAMPP, ComposerSetup, the user Composer directory, or Scoop.
- `composer.json`, `composer.lock`, the target local assets/provenance files, and `ConsoleTest.php` are absent.
- The repository contains a professor-reference HTMX file, but reference material is read-only and was not substituted into the application. No locally verified Bulma application bytes/provenance were selected.

## Command Evidence

| Purpose | Command shape | Exact outcome | Verification consequence |
|---|---|---|---|
| PATH prerequisites | `php --version`; `php -m`; `composer --version`; `mysql --version` | All four names produced `CommandNotFoundException`; no executable exit code was available | Required direct paths had to be boundedly located; Composer remained unavailable |
| PHP runtime | `& "C:\xampp\php\php.exe" --version`; `& "C:\xampp\php\php.exe" -m` | PHP 7.4.20; both exits 0; required PDO/XML/string/crypto/archive modules observed | PHP runtime and listed extensions proven locally, but package compatibility remains unprovable without Composer |
| MariaDB client | `& "C:\xampp\mysql\bin\mysql.exe" --version` | MariaDB 10.4.19, exit 0 | Client version proven; no database mutation or connection attempted |
| Composer candidates | `Get-Command composer` plus bounded `Test-Path` checks | Not found | Fail-closed blocker for manifest/lock, dev tools, scripts, setup, tests, and analysis |

## Work Unit Evidence

| Evidence | Result |
|---|---|
| Focused test command and exact result | `composer test -- --filter ConsoleTest` — NOT RUN: Composer, lock, and test harness are unavailable. Required RED test was not fabricated. |
| Runtime harness command/scenario and exact result | `composer setup` — NOT RUN: Composer and committed locked inputs are unavailable. `composer serve` was consequently not run. |
| Rollback boundary | Delete this `apply-progress.md`; no runtime implementation, dependencies, assets, tests, configuration, task checkboxes, database state, or processes were changed. |

## Files Changed

| File | Action | Purpose |
|---|---|---|
| `openspec/changes/project-foundation-bootstrap/apply-progress.md` | Created | Persist bounded failed-attempt evidence without claiming implementation progress |

## Deviations from Design

None. Implementation stopped at the design's fail-closed compatibility gate.

## Issues

- Primary blocker: Composer is unavailable, so exact package compatibility and a reproducible lock cannot be locally installed or verified.
- Secondary blocker: application HTMX/Bulma asset bytes, version, checksum, source, and license evidence are not yet jointly available for selection.

## Cleanup and Process Evidence

- No dependency installation, vendor/cache output, copied assets, configuration, database connection, migration, server, or test process was created.
- Post-probe process counts were PHP 0, Composer 0, MySQL client 0, MariaDB server (`mysqld`) 1. The server process pre-existed the attempt and was not modified or stopped.

## Remaining Tasks

- [ ] 1.1 through 1.7 — entire PR 1 work unit remains pending.
- [ ] Phase 2 and Phase 3 — out of scope for this slice and untouched.

## PR Boundary

- Intended boundary: reproducible runtime only (Composer/config/assets/scripts/tests/documentation).
- Actual boundary: evidence-only failed attempt; no implementation candidate exists.
- Review budget impact: apply-progress only; below the 400 authored-line limit.

---

## Current Attempt: Reproducible Runtime Implemented

- Mode: Standard (`strict_tdd: false`)
- Slice: PR 1 — `unit-1-reproducible-runtime`
- Chain strategy: stacked-to-main
- Attempt outcome: success
- Implementation evidence revision: `sha256:9a171bdcc0c49fde92f7e00019e6003ff20b9fcd16a2902910a072958fbbc32f`
- Harness disposition: valid; runtime process stopped and port released

## Cumulative Task Progress

- [x] 1.1–1.7 — reproducible runtime complete.
- [ ] 2.1–3.5 — out of scope and untouched.

## Current Diagnosis and Resolution

- PHP 8.5.10 and Composer 2.10.3 resolve through Scoop. Scoop's supplied `php.ini-production` is required through `PHPRC`; without it, the current PHP installation loads no configuration file.
- The required PDO, `pdo_mysql`, mbstring, OpenSSL, DOM, XML, XMLWriter, and ZIP extensions loaded after `PHPRC` was set. Existing XAMPP MariaDB 10.4.19 evidence remains valid and XAMPP was not changed.
- Composer resolved and locked PHPUnit 13.3.2, PHPStan 2.2.13, and 26 transitive packages on PHP 8.5.10. Audit reported no known advisories.
- npm registry metadata and archives verified htmx 2.0.10 and Bulma 1.0.4. Exact local file SHA-256 values, sources, licenses, versions, and review date are recorded under `assets/`.

## Work Unit Evidence

| Evidence | Exact result |
|---|---|
| Threat-matrix RED | `composer test -- --filter ConsoleTest` before production classes: exit 2; 4 tests, 1 assertion, 3 errors and 1 failure because `App\\Foundation\\Console` did not exist. |
| Focused test | `composer test -- --filter ConsoleTest`: exit 0; 5 tests, 7 assertions. Unknown/metacharacter commands caused no effects, a spaced Windows root verified safely, invalid roots failed, and changed assets were rejected. |
| Runtime setup | `composer setup`: exit 0; lock install was unchanged and all local asset checksums verified. Repeated executions also exited 0. |
| Runtime serve | `composer serve`, then GET `/assets/htmx.min.js`: HTTP 200, 51,238 bytes. Cleanup left 0 new PHP processes and 0 port-8000 listeners. |
| Static checks | `composer analyse`: exit 0, no errors. Nine PHP files passed `php -l`. `composer validate --strict` and `composer audit --locked`: exit 0; manifest valid, no advisories. |
| Safe config failure | Invalid `APP_ENV=secret-sentinel`: exit 1 with `Invalid configuration field: APP_ENV`; no supplied value or credential was emitted. |
| Rollback boundary | Remove the 22 implementation paths listed below. This reverts only Composer/config/assets/scripts/tests/documentation and leaves references, XAMPP, MariaDB state, and later work units untouched. |

The first PHPStan execution failed with 8 type-narrowing errors. The code was corrected without suppressions; the final run passed. An intermediate asset-path regular expression emitted one PHPUnit warning; it was replaced with explicit rooted-path checks and final tests passed without warnings.

## Files Added

`.env.example`, `.gitignore`, `README.md`, `composer.json`, `composer.lock`, `phpunit.xml`, `phpstan.neon`, `assets/{LICENSES.md,provenance.json}`, `config/{bootstrap,defaults}.php`, `public/assets/{app.js,bulma.min.css,htmx.min.js}`, `scripts/{console,setup}.php`, `src/Foundation/{AssetVerifier,Config,Console}.php`, `src/Modules/.gitkeep`, and `tests/{bootstrap.php,Integration/ConsoleTest.php}`.

## Cleanup and Process Evidence

- Runtime harness started 4 transient PHP processes through Composer and the PHP development server; all 4 were stopped.
- PHP processes created by the harness after cleanup: 0. Port 8000 listeners after cleanup: 0.
- `mysqld` count was 1 before and 1 after. No database connection, migration, seed, XAMPP mutation, or server stop occurred.
- Temporary npm archives, extraction directories, and harness logs were removed; no temporary runtime artifact remains in the workspace.

## Review Boundary

- Start: documentation/reference-only repository with no implementation runtime.
- End: deterministic locked PHP runtime, validated injected configuration, local verified assets, canonical command surface, and concept-free console tests.
- Follow-up: PR 2 adds HTTP delivery; PR 3 activates migration and seed commands.
- Authored application additions: 456 lines including license documentation, excluding generated `composer.lock` and two verbatim minified third-party asset lines. This exceeds the 400-line budget by 56 lines; no tests, docs, comments, or required behavior were removed. Recommend `size:exception` for this indivisible slice.

---

## PR 2 Initial Attempt: Review Budget Blocker

- Mode: Standard (`strict_tdd: false` from `openspec/config.yaml`)
- Slice: PR 2 — `unit-2-secure-http-delivery` (monolithic attempt)
- Chain strategy: stacked-to-main
- Attempt outcome: blocked before final verification because the cohesive candidate exceeded the review budget
- Blocker evidence: The initial monolithic implementation reached 593 authored additions / 634 total lines before OpenSpec updates, exceeding the 400-line PR threshold by 193 lines.
- Resolution: Maintainer explicitly declined a `size:exception` and mandated a non-destructive reslice into autonomous, reviewable successor units targeting <= 400 lines without discarding verified work.

---

## PR 2 Reslice Execution: Three Autonomous Slices

The monolithic candidate was non-destructively resliced and implemented across three sequential stacked branches, preserving existing RED-test evidence and resolving all 11 PHPStan errors at the root:

### Slice 2A: Routing & Request Safety
- Branch: `foundation/http-routing-safety`
- Commit: `9442f15` (`feat(http): implement safe routing, request parsing, and redirect validation`)
- Stack: `1500f9c` -> `9442f15`
- Authored lines: 304 lines (5 files: `src/Foundation/{Handler,Request,Response,Router}.php`, `tests/Integration/HttpTest.php`)
- Scope: Request/Response value objects, path traversal/separator rejection (HTTP 400), doc-path blocking (HTTP 404), method semantics (HTTP 405 with `Allow`), and strict redirect validation.
- Verification: 22 tests, 36 assertions passing; PHPStan Level 8 with 0 errors (7 errors in `Request.php` and `Router.php` resolved at root).

### Slice 2B: Secure Server-Rendered Interactions
- Branch: `foundation/http-rendered-forms`
- Commit: `d1c3278` (`feat(http): add session, csrf, safe rendering, and health form interaction`)
- Stack: `9442f15` -> `d1c3278`
- Authored lines: 352 lines (11 files: `src/Foundation/{Session,NativeSession,Csrf,ValidationResult,Renderer,HealthHandler}.php`, 4 templates, +101 lines in `tests/Integration/HttpTest.php`)
- Scope: Native session adapter, cryptographic CSRF token generation/validation (HTTP 403 on failure), strongly-typed validation DTO, nested buffer unwinding and HTML entity escaping in renderer, health form with dual full-page/fragment rendering, PRG (HTTP 303), and HTMX headers (`HX-Redirect`, `HX-Trigger`).
- Verification: 25 tests, 55 assertions passing; PHPStan Level 8 with 0 errors (4 errors resolved at root).

### Slice 2C: Front-Controller & Correlated Failure Boundary
- Branch: `foundation/http-boundary`
- Commit: `1ff6503` (`feat(http): establish front controller, kernel, and correlated error logging`)
- Stack: `d1c3278` -> `1ff6503`
- Authored lines: 185 lines (8 files: `src/Foundation/{Logger,ErrorMapper,Kernel}.php`, `config/routes.php`, `public/index.php`, `templates/error.php`, +1 line in `src/Foundation/Renderer.php`, +65 lines in `tests/Integration/HttpTest.php`)
- Scope: Thin web front-controller (`public/index.php`), centralized route declaration (`config/routes.php`), kernel dispatch pipeline, generic 500 error mapping with 16-hex correlation token, and single-line structured JSON `error_log` adapter without leaking stack traces, file paths, or secrets.
- Authorized Integration Fix: `Renderer.php` allowlist gained `'error' => 'error.php'` (1 line) to allow rendering the error view, alongside a robust last-resort fallback in `ErrorMapper` preventing recursive failure if template evaluation throws.
- Verification: 27 tests, 67 assertions passing; PHPStan Level 8 with 0 errors; `composer setup` clean.

---

## Cumulative Unit 2 Verification Evidence

| Evidence | Command / Source | Exact Outcome | Verification Status |
|---|---|---|---|
| Complete Test Suite | `composer test` | Exit 0; 27 tests, 67 assertions, 0 failures, 0 warnings | PASS |
| Dependency / Asset Setup | `composer setup` | Exit 0; locked dependencies, autoload, and local assets verified | PASS |
| Failure Boundary | `HttpTest::testUnexpectedFailureIsGenericCorrelatedAndLoggedOnce` | Exit 0; generic HTTP 500 with correlation ID, no secrets/traces leaked, logged once | PASS |
| Last-Resort Fallback | `HttpTest::testErrorMapperFallbackHandlesRendererFailureWithoutThrowing` | Exit 0; fallback HTML emitted with correlation ID, no recursive throw | PASS |
| Working Tree State | `git status` | Clean after Slice 2C commit `1ff6503` | PASS |

## Task & Review Status After Unit 2

- [x] Tasks 1.1–1.7: Unit 1 reproducible runtime complete (`1500f9c`).
- [x] Tasks 2.1–2.5: Unit 2 secure HTTP delivery complete across 3 autonomous review slices (`9442f15`, `d1c3278`, `1ff6503`).
- [ ] Tasks 3.1–3.5: Unit 3 transactional data foundation remains pending.

---

## Unit 3 Execution: Transactional Data Foundation (Three Stacked Slices)

Unit 3 implements the isolated transactional persistence foundation, serialized migration engine, and idempotent development seeding across three autonomous, reviewable stacked slices:

### Slice 3A — Connection, Transactions & Test Isolation
- Branch: `foundation/db-connection-transactions`
- Commit: `08fab21` (`feat(db): establish isolated PDO and transaction foundation`)
- Stack: `0fb6b73` -> `08fab21`
- Authored lines: 399 / 400 (5 files: `src/Foundation/{Database,Transaction,HealthQuery}.php`, `tests/Integration/DatabaseTest.php`, `tests/Unit/ContractsTest.php`)
- Scope:
  - PDO connection foundation: MariaDB utf8mb4, `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `ATTR_EMULATE_PREPARES=false`, `PERSISTENT=false`, `Pdo\Mysql::ATTR_MULTI_STATEMENTS=false`, session `time_zone = '+00:00'`.
  - Strict test DB isolation: fails closed unless `TEST_DB_NAME` ends in `_test`.
  - DML transaction coordinator: `Transaction::run()` commits on normal return, rolls back on `Throwable`, rejects nested transactions with `LogicException`.
  - Parameterized `HealthQuery`: binds `:probe` parameter; zero SQL string concatenation.
  - Contract & Integration proofs: `ContractsTest` (reflection/structural contracts, isolation guard) and `DatabaseTest` (PDO flags, UTC session, transient temporary tables for commit/rollback proofs, read-only dev connection probe).
- Verification: 26 tests, 39 assertions passing; PHPStan Level max clean with 0 errors.

### Slice 3B — Serialized Migration Engine & Drift Protection
- Branch: `foundation/db-migration-engine`
- Commit: `bdaa0a3` (`feat(db): add serialized migration engine and drift protection`)
- Stack: `08fab21` -> `bdaa0a3`
- Authored lines: 377 / 400 (5 files: `src/Foundation/MigrationRunner.php`, `src/Foundation/Console.php` (migrate wiring), `database/migrations/0001_probe.{up,down}.sql`, `tests/Integration/MigrationTest.php`)
- Scope:
  - Deterministic alphanumeric migration ordering (`sort($files)`).
  - MariaDB application-and-database-scoped advisory locking (`ferreto_migrations_lock_<dbName>`).
  - Mandatory lock acquisition verification (`GET_LOCK() === 1`) and guaranteed release in `finally`.
  - Pre-execution checksum drift validation: applied migrations verified against `schema_migrations` before any pending migrations execute.
  - Fail-stop execution: immediate halt on invalid SQL without recording false history.
  - Post-success migration history recording: inserted only after DDL statement succeeds without throwing.
  - Compensating `.down.sql` reversal: executes down DDL statement first; deletes history record only after DDL succeeds. Failed down migration preserves existing history record.
  - Single DDL statement per file; no multi-statement dependency.
- MariaDB DDL Semantics Planning Correction:
  - DDL statements (`CREATE TABLE`, `ALTER TABLE`, `DROP TABLE`) cause implicit commits in MariaDB and are NOT transactionally rollbackable.
  - Replaced the initial concept of "atomic single-file migration rollback" with serialized, fail-stop migration execution.
  - `.down.sql` is treated as an intentional, explicit compensating reversal, not an automatic transaction rollback.
  - Migration history is recorded only after PDO execution succeeds without throwing.
- Verification: 9 tests, 22 assertions passing; PHPStan Level max clean with 0 errors.

### Slice 3C — Idempotent Seeds & Runtime Verification
- Branch: `foundation/db-seeds-verification`
- Commit: `20b8032` (`feat(db): add idempotent development seeding and runtime verification`)
- Stack: `bdaa0a3` -> `20b8032`
- Authored lines: 183 / 400 (7 files: `src/Foundation/SeedRunner.php`, `src/Foundation/Console.php` (seed wiring), `database/seeds/development.php`, `tests/Integration/SeedTest.php`, `tests/E2E/checklist.md`, `openspec/config.yaml`, `openspec/testing-capabilities.md`)
- Scope:
  - `SeedRunner`: transactional execution of environment-specific seed callable; refuses `APP_ENV=production` with `RuntimeException`.
  - `database/seeds/development.php`: deterministic and idempotent fixed-key upsert (`id = 1`) on `infrastructure_probe`.
  - Schema dependency: seed assumes required migration/schema is already applied and fails clearly if it is not; does not silently create tables.
  - `Console.php`: wired `seed` command to `SeedRunner`.
  - Canonical runtime lifecycle: verified `setup → migrate → seed → seed (idempotency) → test → analyse → serve`.
  - `tests/E2E/checklist.md`: documents verified runtime, HTTP delivery, and database capabilities without business flows.
  - `openspec/{config.yaml,testing-capabilities.md}`: updated with verified commands, stack, layers, and quality tools.
- Verification: 5 tests, 11 assertions passing; PHPStan Level max clean with 0 errors.

---

## Cumulative Unit 3 Verification Evidence

| Evidence | Command / Source | Exact Outcome | Verification Status |
|---|---|---|---|
| Complete Test Suite | `composer test` | Exit 0; 67 tests, 139 assertions, 0 failures, 0 warnings, 0 deprecations | PASS |
| Static Analysis | `composer analyse` | Exit 0; PHPStan 2.2.13 (Level max), 26/26 files analysed, 0 errors, no suppressions/baseline | PASS |
| Dependency / Asset Setup | `composer setup` | Exit 0; locked dependencies, autoload, and local assets verified | PASS |
| Migration CLI | `composer migrate` | Exit 0; applied `0001_probe.up.sql` against isolated test DB under advisory lock | PASS |
| Seed CLI (Run 1) | `composer seed` | Exit 0; inserted probe row (`id = 1`) into `infrastructure_probe` | PASS |
| Seed CLI (Run 2 - Idempotency) | `composer seed` | Exit 0; row count remained exactly 1; no duplicate rows, no errors | PASS |
| Serve / Health Probe | `composer serve` / `php -S` | HTTP 200 returned on `GET /health` with CSRF token; server process terminated cleanly | PASS |
| Production Seed Refusal | `SeedTest::testSeedRefusesProductionEnvironment` | Exit 0; `APP_ENV=production` rejected with `RuntimeException` | PASS |
| Missing Schema Guard | `SeedTest::testSeedFailsClearlyWhenSchemaNotApplied` | Exit 0; clear failure when `infrastructure_probe` does not exist | PASS |
| Development DB Protection | Direct PDO query on `sistema_ferreto` | 0 tables present; schema remained 100% untouched and unmutated | PASS |
| Test DB Cleanup | `DatabaseTest`, `MigrationTest`, `SeedTest` tearDown | 0 tables remaining in `sistema_ferreto_test` after suite execution | PASS |
| Scope Boundaries | Codebase inspection | Zero business-domain R1–R10 schema, data, auth, ETL, or DW objects | PASS |

Note: Unit 3 strictly isolated all mutation-capable verification to `sistema_ferreto_test` using environment-based redirection in child processes. No passwords, credentials, or sensitive connection parameters were exposed, persisted, or logged.

---

## Task & Review Status After Unit 3

- [x] Tasks 1.1–1.7: Unit 1 reproducible runtime complete (`1500f9c`).
- [x] Tasks 2.1–2.5: Unit 2 secure HTTP delivery complete across 3 autonomous review slices (`9442f15`, `d1c3278`, `1ff6503`).
- [x] Tasks 3.1–3.5: Unit 3 transactional data foundation complete across 3 autonomous review slices (`08fab21`, `bdaa0a3`, `20b8032`).
