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
