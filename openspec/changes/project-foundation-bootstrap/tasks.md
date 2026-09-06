# Tasks: Project Foundation Bootstrap

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated changed lines | 1,100–1,500 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 → PR 2 → PR 3 |
| Delivery strategy | ask-on-risk |
| Chain strategy | stacked-to-main |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| 1 | Reproducible runtime | PR 1 | `composer test -- --filter ConsoleTest` | `composer setup`; `composer serve` | Composer/config/assets/scripts |
| 2 | Secure HTTP delivery | PR 2 | `composer test -- --filter HttpTest` | `php -S localhost:8000 -t public public/index.php` health page | HTTP/Foundation/templates/assets app.js |
| 3 | Transactional data foundation | PR 3 | `composer test -- --filter DatabaseTest` | `composer migrate`; `composer seed` against `_test` | Database Foundation/migrations/seeds/tests/docs |

## Phase 1: Reproducible Runtime

- [ ] 1.1 Verify PHP/extensions, Composer, MariaDB, package, and asset compatibility; record exact selections or fail closed in `README.md`.
- [ ] 1.2 Add pinned `composer.json`, `composer.lock`, PSR-4/dev tooling, fixed `@php` `scripts/{console,setup}.php`, and `src/Modules/.gitkeep`.
- [ ] 1.3 Add `.env.example`, `.gitignore`, and `config/{defaults,bootstrap}.php` validation that names invalid fields without secrets.
- [ ] 1.4 Add reviewed local `public/assets/{htmx.min.js,bulma.min.css,app.js}` and `assets/{provenance.json,LICENSES.md}`; setup verifies checksums without substitution.
- [ ] 1.5 RED: add `tests/Integration/ConsoleTest.php` proving unknown/metacharacter command arguments cause no effects and a Windows path containing spaces runs safely.
- [ ] 1.6 Implement the fixed allowlist/root validation in `src/Foundation/{Console,AssetVerifier,Config}.php` and make the RED console cases pass.
- [ ] 1.7 Document the PowerShell environment, locked setup, asset, and Windows workflow in `README.md`; add `tests/bootstrap.php`, `phpunit.xml`, and `phpstan.neon`.

## Phase 2: Secure HTTP Delivery

- [ ] 2.1 RED: add `tests/Integration/HttpTest.php` for 404/no execution of `requirements.txt`, `CMakeLists.txt`, executable Markdown/MDX, and `README.sh`.
- [ ] 2.2 RED: add HTTP cases for traversal/encoded separators→400, external/CRLF redirects rejected, and unknown→404 versus wrong method→405 with `Allow`.
- [ ] 2.3 Implement `public/index.php`, `config/routes.php`, and `src/Foundation/{Kernel,Request,Response,Router,Handler,HealthHandler}.php` to satisfy route and static allowlist RED tests.
- [ ] 2.4 Implement `src/Foundation/{Renderer,ValidationResult,Csrf,Session,NativeSession,Logger,ErrorMapper}.php` and `templates/{layout,pages/health,fragments/health,fragments/notification,error}.php` for escaped full/HTMX 422, CSRF 403, safe redirects, notifications, and generic correlated 500s.
- [ ] 2.5 Extend `tests/Integration/HttpTest.php` with full/fragment/no-JS, escaped output, validation, CSRF, PRG/HX headers, and generic-error scenarios.

## Phase 3: Transactional Data Foundation

- [ ] 3.1 Add `src/Foundation/{Database,Transaction,MigrationRunner,SeedRunner,HealthQuery}.php`: `_test` refusal, PDO flags/UTC/binding, rollback, migration lock/history/checksum/failure stop, development-only seed.
- [ ] 3.2 Add `database/migrations/0001_probe.{up,down}.sql` and `database/seeds/development.php` for only the infrastructure probe and idempotent fixed-key seed.
- [ ] 3.3 Add `tests/{Unit/ContractsTest.php,Integration/DatabaseTest.php,Integration/MigrationTest.php}` for binding, commit/rollback, isolated database protection, migration order/drift/failure, seed repetition, and no business/ETL objects.
- [ ] 3.4 Run setup→migrate→seed→test→analyse→serve and the browser checklist in `tests/E2E/checklist.md`; structurally read back routes, assets, schema, and no R1–R10 scope.
- [ ] 3.5 Only after successful commands, update `openspec/{config.yaml,testing-capabilities.md}` with executable capabilities and verified commands.
