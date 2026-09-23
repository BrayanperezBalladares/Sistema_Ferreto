# Tasks: Access Control Foundation

## Phase A: Account Persistence Foundation (Slice A1 & A2)

- [x] A.1 Create additive migration `database/migrations/0007_create_usuario.up.sql` (`usuario` table: `id_usuario` PK, `username` VARCHAR(50) UNIQUE, `password_hash` VARCHAR(255), `rol` VARCHAR(30), `estado` VARCHAR(20) DEFAULT 'creado', `failed_attempt_count` INT UNSIGNED DEFAULT 0, `failure_window_started_at` TIMESTAMP NULL, `locked_at` TIMESTAMP NULL, `created_at`, `updated_at`, portable CHECK constraints for roles `administrador`/`cajero`/`bodeguero`/`compras` and states `creado`/`activo`/`bloqueado`/`inactivo`) and reversal `0007_create_usuario.down.sql`.
- [x] A.2 Implement `src/Modules/Access/UserQuery.php` providing read-only PDO methods `findById(int $id): ?array` and `findByUsername(string $username): ?array` using prepared statements.
- [x] A.3 Implement `src/Modules/Access/UserCommand.php` for account insertion (`create`), setting explicit state, role, and hashed password.
- [x] A.4 Implement in `src/Modules/Access/UserCommand.php` atomic failed-attempt tracking (`recordFailure`), failure metadata reset (`resetFailures`), and administrative unlock mutation (`unlock`) enforcing `bloqueado -> activo` transition.
- [x] A.5 Add integration test `tests/Integration/UserPersistenceTest.php` verifying schema constraints, username uniqueness, account states, atomic failure updates, failure resets, and unlock state validation against test DB.

## Phase B: Administrative User Management CLI (Slice B1 & B2)

- [x] B.1 Wire CLI commands in `src/Foundation/Console.php` to dispatch multi-argument commands `create-user` and `unlock-user`.
- [x] B.2 Implement interactive `create-user <username> <rol>` in `src/Modules/Access/UserCliHandler.php` with non-echo terminal input (Windows PowerShell / POSIX stty), fail-closed enforcement on insecure terminals, password contract validation (minimum 15 Unicode codepoints, maximum 72 UTF-8 bytes, no silent truncation), bcrypt hashing with cost 10, duplicate rejection, and explicit `activo` state creation.
- [x] B.3 Implement `unlock-user <username>` in `src/Modules/Access/UserCliHandler.php` transitioning `bloqueado` accounts to `activo` and clearing failure metadata, while rejecting nonexistent, `creado`, or `inactivo` accounts.
- [x] B.4 Add integration tests `tests/Integration/ConsoleUserTest.php` validating `create-user` (valid input, length/byte limits, multibyte handling, duplicate username, echo fail-closed) and `unlock-user` (blocked account transition, rejection of inactive/created/missing accounts).

## Phase C: Authentication Core & Session Lifecycle (Slice C1 & C2)

- [x] C.1 Implement `src/Modules/Access/Authenticator.php` orchestrating credential validation with `password_verify()`, precomputed dummy bcrypt execution for nonexistent usernames to mitigate timing leaks, and UTF-8 72-byte ceiling rejection before hashing.
- [x] C.2 Implement fixed first-failure 10-minute lockout window logic in `Authenticator.php`: attempt 1 starts window; attempts 1–5 stay active; attempt 6 within window transitions account to `bloqueado`; expired window resets counter to 1; successful login clears failure metadata; blocked account with valid password denied.
- [x] C.3 Extend `src/Foundation/NativeSession.php` with `regenerate(): void` (invoking `session_regenerate_id(true)` to prevent fixation), `destroy(): void`, and cookie expiration helper.
- [x] C.4 Implement current-user persistent revalidation in `src/Modules/Access/AuthSession.php`: re-query DB on each request by `auth_user_id`, immediately revoking session if missing or `estado !== 'activo'`.
- [x] C.5 Implement role-aware inactivity tracking in `AuthSession.php`: 20 minutes for `cajero`, configurable default (30 minutes in `config/defaults.php` via `SESSION_IDLE_TIMEOUT`) for other roles, clearing expired sessions on protected requests.
- [x] C.6 Add concurrency tests in `tests/Integration/LockoutConcurrencyTest.php` proving parallel failed attempts do not lose increments and the 6th failure reliably locks the account.
- [x] C.7 Add integration tests `tests/Integration/SessionLifecycleTest.php` proving session fixation regeneration, stale-state revocation, role updates during open sessions, and inactivity expiration.
- [x] C.8 Ensure no logging of passwords, hashes, session IDs, or CSRF tokens in `src/Foundation/Logger.php` or handlers, verified by `tests/Integration/SensitiveLoggingTest.php`.

## Implementation Prerequisites: Access Foundation Support (Slice Pre-D1)

- [x] Pre-D1.1 Passive session inspection in `src/Modules/Access/AuthSession.php` (`peekUser()`).
- [x] Pre-D1.2 Centralized canonical route authorization policy in `src/Modules/Access/RouteAccessPolicy.php`.

## Phase D: HTTP Login & Logout Delivery (Slice D1)

- [x] D.1 Implement `src/Modules/Access/AccessHandler.php` handling `GET /login`, `POST /login`, and `POST /logout` with CSRF protection, returning HTTP 422 with generic error copy for failed credentials.
- [x] D.2 Implement return-after-login in `AccessHandler.php`: validate relative destination (`/` prefix, rejecting `//` and external URLs), store in session, redirect to destination on login if role authorized, or fallback to `/products`.
- [x] D.3 Implement complete logout in `AccessHandler.php`: clear session data, destroy server session, expire client cookie, and redirect 303 to `/login`.
- [x] D.4 Create responsive login template `templates/pages/login.php` adhering to `docs/ui/DESIGN.md` (single-column card, 360px viewport support, visible labels, autocomplete attributes, >=44px touch targets).
- [x] D.5 Register routes in `config/routes.php` (`GET /login`, `POST /login`, `POST /logout`) and wire dependencies in `public/index.php`.
- [x] D.6 Add HTTP integration tests `tests/Integration/AccessHttpTest.php` verifying login success/failure, 422 generic response, 303 redirects, safe return paths, open redirect rejection, already-authenticated redirect, and logout destruction.

## Phase E: Authentication & Role Authorization Guards (Slice E1 & E2)

- [x] E.1 Implement `src/Modules/Access/AuthGuard.php` intercepting requests before handler dispatch: allow public routes (`GET /login`, `POST /login`, `GET /health`), redirect unauthenticated browser requests with 303 to `/login`, and intercept unauthenticated HTMX requests with HTTP 200 + `HX-Redirect: /login` and empty body.
- [x] E.2 Implement `src/Modules/Access/RoleGuard.php` evaluating the authoritative fresh DB role against the exact R1 route matrix: `administrador` (all 14 routes), `bodeguero` (catalog read, locations, inventory stock, counts), `cajero` (catalog read only), `compras` (catalog read only).
- [x] E.3 Integrate `AuthGuard` and `RoleGuard` into `src/Foundation/Kernel.php`, ensuring unauthenticated requests redirect and unauthorized requests return HTTP 403 Forbidden without executing handlers or mutations.
- [x] E.4 Add guard integration tests `tests/Integration/RoleGuardHttpTest.php` exercising the complete matrix for all 4 roles across all 14 business routes, unauthenticated browser 303 redirect, unauthenticated HTMX 200 + `HX-Redirect`, and 403 authorization denials.

## Phase F: Infrastructure Health Boundaries (Slice F1)

- [x] F.1 Update `src/Foundation/HealthHandler.php` ensuring `GET /health` remains an anonymous minimal liveness probe, and gate `POST /health` behind `APP_ENV !== 'production'` (returning HTTP 405 Method Not Allowed with `Allow: GET`).
- [x] F.2 Add integration tests `tests/Integration/HealthBoundaryTest.php` verifying public access to `GET /health` and rejecting `POST /health` with 405 Method Not Allowed in simulated production mode (`APP_ENV=production`).

## Phase G: Authenticated Shell & Role-Aware UI (Slice G1 & G2)

- [x] G.1 Update `templates/layout.php` and navigation to render username, human-readable role badge, and touch-accessible logout button (>=44px) in topbar and mobile drawer.
- [x] G.2 Conditionally suppress unauthorized operational buttons in `templates/pages/products.php`, `templates/pages/locations.php`, `templates/pages/inventory.php`, and `templates/pages/counts.php` based on user role (`cajero`/`compras` cannot see mutation controls; `bodeguero` cannot see catalog mutation controls).
- [ ] G.3 Add UI integration tests `tests/Integration/AuthenticatedUiTest.php` verifying layout context display and role-based action control suppression in rendered HTML.

## Phase H: Test Suite Authentication Migration (Slice H1)

- [x] H.1 Create test authentication trait `tests/Support/AuthSessionTrait.php` for establishing authenticated principal sessions in the `_test` database without disabling guards or bypassing security code.
- [x] H.2 Migrate existing R1 test suites (`CatalogHttpTest.php`, `LocationHttpTest.php`, `InventoryHttpTest.php`, `CountHttpTest.php`) to execute with appropriate authenticated roles.
- [x] H.3 Verify complete suite execution under `composer test` proving all R1 existing test cases pass cleanly with authentication guards active.

## Phase I: Security & R1 Regression Verification (Slice I1)

- [ ] I.1 Verify end-to-end R1 business domain invariants: product creation, price update, activation/deactivation, location creation, stock position uniqueness, and observational counts non-mutation for authorized roles.
- [ ] I.2 Verify development database invariance: ensure automated tests execute exclusively in `_test` DB and leave development tables untouched.
- [ ] I.3 Run full canonical verification: `composer setup`, `composer test`, `composer analyse`, `openspec validate access-control-foundation`, and `openspec validate --specs`.

---

## Implementation Slices & Commit Plan

- **Slice A1**: Migration `0007_create_usuario` (up/down) and schema integration test.
- **Slice A2**: `UserQuery` and `UserCommand` implementation with atomic failure tracking and persistence tests.
- **Slice B1**: CLI framework extension in `Console.php` and `create-user` command with tests.
- **Slice B2**: `unlock-user` CLI command and unlock tests.
- **Slice C1**: `Authenticator` core logic, timing attack mitigation, and first-failure 10-minute lockout tests.
- **Slice C2**: `NativeSession` enhancements, `AuthSession`, inactivity expiration, and concurrency tests.
- **Slice Pre-D1**: Implementation prerequisites (`AuthSession::peekUser()` passive session inspection, `RouteAccessPolicy` canonical authorization matrix).
- **Slice D1**: `AccessHandler`, `GET/POST /login`, `POST /logout`, return-after-login, responsive login view, and HTTP tests.
- **Slice E1**: `AuthGuard` implementation, HTMX session expiry handling, and browser redirect tests.
- **Slice E2**: `RoleGuard` implementation, R1 route authorization matrix enforcement, and 403 tests.
- **Slice F1**: `HealthHandler` production boundary gating and health probe tests.
- **Slice G1**: `templates/layout.php` shell integration (username, role badge, logout button).
- **Slice G2**: Role-aware button suppression in operational views and rendered HTML tests.
- **Slice H1**: Test authentication helper and migration of existing R1 integration tests.
- **Slice I1**: Full R1 domain regression check, development database invariance, static analysis, and OpenSpec validation.
