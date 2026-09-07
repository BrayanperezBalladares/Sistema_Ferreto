# Design: Project Foundation Bootstrap

## Technical Approach

Implement `reproducible-project-runtime`, `server-rendered-http-delivery`, and `transactional-data-foundation`. Evidence: proposal/exploration, professor PDF/POC. Approved scope overrides stale five-file/dual-engine config context; preserve references. No R1–R10 implementation.

## Architecture Decisions

| Option | Tradeoff | Decision |
|---|---|---|
| Modular monolith / services | R2/R3/R9 stock changes share R1 facts, R5 controls and R6 tracing | One deployable, atomic OLTP; empty Modules boundary |
| Native router / framework | Few routes; framework adds machinery | Explicit table; no per-action scripts |
| Native SQL runner / Phinx | One engine; runner needs safety tests | Purpose-built runner |
| Injected environment / phpdotenv | Manual local injection; no parser dependency | Native `getenv`; `.env` never loaded |
| `error_log` / Monolog | Limited sinks; sufficient diagnostics | JSON adapter with allowlisted context |
| PDO / ORM/portability | Explicit SQL ownership | MariaDB-only production OLTP; no repositories |

## Data Flow

```text
Browser -> public/index.php -> Kernel/Router -> Handler -> Query -> PDO/MariaDB
                                      <- Renderer <- result
CLI -> validated Config -> migration/seed/test
```

## File Changes

Create files (braces enumerate):

| Paths | Responsibility |
|---|---|
| `composer.{json,lock}`, `.env.example`, `.gitignore`, `README.md` | Dependencies, secrets, workflow |
| `config/{bootstrap,routes,defaults}.php` | Composition/table/defaults |
| `public/index.php`, `src/Modules/.gitkeep` | Sole front controller; future boundary |
| `src/Foundation/{Kernel,Request,Response,Router,Handler,Renderer,ValidationResult,Csrf,Session,NativeSession,Logger,ErrorMapper,Config,Database,Transaction,MigrationRunner,SeedRunner,HealthHandler,HealthQuery,AssetVerifier,Console}.php` | Contracts/adapters below |
| `templates/{layout,pages/health,fragments/health,fragments/notification,error}.php` | HTML representations |
| `public/assets/{htmx.min.js,bulma.min.css,app.js}`, `assets/{provenance.json,LICENSES.md}` | Reviewed local assets |
| `database/migrations/0001_probe.{up,down}.sql`, `database/seeds/development.php` | Infrastructure probe(id,created_at); fixed-key seed |
| `scripts/{console,setup}.php`, `phpunit.xml`, `phpstan.neon` | Commands/tool configuration |
| `tests/{bootstrap.php,Unit/ContractsTest.php,Integration/HttpTest.php,Integration/DatabaseTest.php,Integration/MigrationTest.php,Integration/ConsoleTest.php,E2E/checklist.md}` | Proofs |

After implementation, update `openspec/{config.yaml,testing-capabilities.md}` with verified commands/capabilities.

## Interfaces / Contracts

| Boundary | Contract |
|---|---|
| HTTP | Request: method, path, separate query/body/route-parameter maps, case-insensitive headers. Response: status/headers/body; only emitter outputs. `Handler(Request):Response`. |
| Routes | Unique name/method/path/handler; anchored `{name}` single-segment parameters, decoded once; query excluded. `/health`: GET `health.show`, POST `health.check`; no method override. Unknown=404; wrong method=405+Allow. Duplicate registrations fail startup. |
| Rendering | `render(allowedName,data):string`, buffered/discarded on failure. Same result feeds page/fragment; `HX-Request:true` selects fragment; `Vary: HX-Request`, no-store. Escape text/attributes; encode route URLs; no untrusted JS/CSS. |
| Forms | `ValidationResult(safeInput,fieldErrors)`; invalid=422. Native action/method plus HTMX attributes. Normal success=303 named GET+session flash; HTMX navigation=200+HX-Redirect; in-place=200+JSON HX-Trigger `notification:{message,level}`. app.js only enables 422 swaps and textContent notifications; navigation uses flash. |
| Safety | Session get/set/remove seam, no authentication; HttpOnly/SameSite=Lax, Secure outside localhost. Random session CSRF token via hidden field or X-CSRF-Token; constant-time check before unsafe handlers; failure=403. |
| Errors/config | Unexpected=generic 500+generated correlation ID; log once: ID, route, exception class/location, never message/arguments/secrets. Config validates APP_ENV, DB_HOST/PORT/NAME/USER/PASSWORD, TEST_DB_* before dependent work; diagnostics name fields only. Ignore `.env*` except example, vendor/cache/local secrets. |
| Data | PDO mysql/utf8mb4; ERRMODE_EXCEPTION, FETCH_ASSOC, EMULATE_PREPARES=false, PERSISTENT=false, MYSQL_ATTR_MULTI_STATEMENTS=false; UTC connection. Bind all values; identifiers fixed/allowlisted. Module-owned queries/commands, never handler PDO. `Transaction::run(callable):mixed` commits/rolls back Throwable; nesting rejected. HealthQuery binds `SELECT :probe`. |
| Schema | Numbered up/down SQL: one statement/file; runner-bootstrapped history(identifier, SHA256, UTC applied_at). Exclusive MariaDB advisory lock; validate history first; drift/failure stops, no applied record. DDL partial failure requires inspection, never automatic retry. SeedRunner development-only transactional upsert of infrastructure probe, no business data. |

## Workflow, Testing, Rollout

Composer PSR-4 `App\\`→`src/`, test autoload; PHPUnit/PHPStan dev-only. Locally verify runtime/extensions/MariaDB/packages/assets, review available advisories, then pin manifest/lock and runtime record in README; unverifiable compatibility blocks implementation. Provenance records source/version/SHA256/review-date/license; setup verifies bytes, never substitutes.

Native Windows prerequisites; no Docker. README documents PowerShell `$env:` injection. Composer setup=locked install+validation; remaining scripts use fixed `@php` entrypoints: migrate, seed, test=PHPUnit, analyse=PHPStan, serve=localhost PHP server through index; no shell interpolation. Exit 0/success, nonzero/failure. Order: setup→migrate→seed→test→analyse→serve.

Unit tests cover contracts; integration traverses front controller, validation/CSRF/errors, binding/commit/rollback/migration drift. Require isolated MariaDB database with restricted test-only credentials, distinct name ending `_test`; refuse missing/production target, never skip; reset only owned objects. Browser checklist proves HTMX/no-JS/offline-assets. Record capabilities after successful execution.

## Threat Matrix

Applicable rows propagate unchanged into tasks/RED tests.

| Boundary | Applicability; safe/failure behavior; RED test |
|---|---|
| Documentation-like paths | Applicable: static allowlist only; 404/no execution for requirements.txt, CMakeLists.txt, executable Markdown/MDX, README.sh; HTTP test each class. |
| Git repository selection | N/A: no git -C/relative/absolute repository selectors. |
| Commit state | N/A: no staged/commit -a/empty-index automation. |
| Push state | N/A: no tracking/first-push/refspec automation. |
| PR commands | N/A: no --head/environment-prefix/composed PR commands. |
| Routing | Applicable: traversal/encoded separators→400; external/CRLF redirects rejected; HTTP RED test each, including 404/405. |
| Commands | Applicable: fixed command allowlist/root; unknown/metacharacter arguments fail before effects; Console RED tests plus spaced Windows path. |

## Migration / Rollout / Open Questions

Gate release on proofs. Stop serving before rollback; inspect partial DDL, run reviewed reverse-order downs, restore files; never erase history automatically. No business migration. Future extraction: stable IDs/UTC timestamps/retained history only. Deferred: verified versions, hosting/authentication/ETL/DW topology; no architecture blockers.
