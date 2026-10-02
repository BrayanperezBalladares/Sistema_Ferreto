# Proposal: Access Control Foundation

## Intent

Establish the baseline authentication, session security, and role-based access control (RBAC) foundation required to govern operational access across **Ferreterías El Constructor**. Currently, all R1 catalog, location, stock, and physical count routes are accessible anonymously without digital identity or authorization checks. This change introduces secure credentials verification, server-side session management, single-role authorization guards, and an interactive administrator bootstrap mechanism while strictly preserving R1 domain behavior.

## Why

The system currently exposes sensitive operational state changes (creating products, modifying selling prices, creating physical storage locations, establishing stock, and logging physical counts) to unauthenticated requests. A digital identity and authorization barrier is mandatory before introducing Point of Sale (R2), Purchases (R3), or Transactional Audit Logging (R6).

## Scope

### In Scope
- **User Account Persistence**: Minimal `usuario` relational entity with unique `username`, secure bcrypt `password_hash`, single `rol` attribute, and explicit lifecycle status.
- **Account Lifecycle Enforcement**: Semantic distinction among `Creado`, `Activo`, `Bloqueado` (temporary security lockout), and `Inactivo` (administrative deactivation).
- **Basic Account Lockout**: Automatic transition to `Bloqueado` after more than 5 consecutive failed login attempts within 10 minutes, requiring administrative unlock.
- **Credential Verification**: Native PHP bcrypt `password_verify()` via module-owned commands/queries.
- **Server-Side Session Authentication**: Native PHP session lifecycle (`GET /login`, `POST /login`, `POST /logout`) with `HttpOnly`, `SameSite=Lax`, `Secure` (production) cookie flags, ID regeneration on login, and explicit destruction on logout.
- **Inactivity Session Expiration**: Mandatory 20-minute inactivity timeout for `cajero` sessions; configurable inactivity expiration for other roles.
- **Server-Side Route Protection**: Interceptor guards enforcing authenticated sessions and role authorization before handler dispatch across all R1 business routes.
- **R1 Access Matrix**: Provisional maintainer-approved baseline (`administrador` full R1 access; `bodeguero` locations, stock, counts, product read; `cajero` product read-only; `compras` product read-only).
- **Safe Post-Login Redirection**: Retention of validated relative internal application paths in server session state to redirect post-authentication.
- **HTMX & Unauthenticated Handling**: Navigation redirects to `/login` for unauthenticated browser requests; `HX-Redirect: /login` for HTMX requests; `403 Forbidden` for authenticated unauthorized requests.
- **Authenticated Shell Context**: User status display (username, role badge) and touch-safe logout action in the persistent application layout.
- **Secure Bootstrap Administrator CLI**: Dedicated interactive CLI command (`scripts/console.php create-user`) creating an administrator without committed secrets or default accounts.

### Out of Scope
- User Administration UI (user listing, CRUD modals, role reassignment screens).
- Self-service password recovery (email/SMS reset).
- Multi-factor authentication (MFA/2FA), OAuth, or SSO.
- Detailed transactional audit logging engine (`REGISTRO_ACCION_LOG` deferred to R6).
- Physical branch hierarchy binding (`SUCURSAL.id_sucursal` deferred to R9).
- Dynamic multi-table permission matrices (`usuario_rol`, `rol_permiso`).
- Multiple concurrent roles per user account.
- Advanced distributed rate limiting, Redis infrastructure, or CAPTCHA.
- Business domains for Point of Sale (R2) or Purchasing (R3).

## Capabilities

### New Capabilities
- `access-control-foundation`: User identity, credential verification, session management, account lifecycle, role authorization guards, and administrator bootstrap.

### Modified Capabilities
- `server-rendered-http-delivery`: Route dispatching contract updated to integrate server-side authentication and authorization guards prior to handler invocation.

## Approach

Implement an isolated access module in `src/Modules/Access/` (or `Auth/`) integrated with `src/Foundation/`:
- Ordered migration creates the `usuario` table with unique constraint on `username` and CHECK constraints on `rol` (`administrador`, `cajero`, `bodeguero`, `compras`).
- `NativeSession` is extended to support cryptographic session regeneration (`session_regenerate_id(true)`), session destruction, last-activity timestamps, and authenticated user context.
- `Kernel` and `Router` dispatch chain incorporates authentication/authorization guards evaluating route requirements before handler execution.
- Operational R1 endpoints (`/products`, `/categories`, `/locations`, `/inventory`, `/inventory/counts`) transition from anonymous access to protected access.
- `GET /health` is maintained as a minimal unauthenticated deployment liveness check, with its production posture finalized during Design.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `database/migrations/` | New | Ordered migration creating `usuario` table and constraints. |
| `src/Foundation/` | Modified | Session regeneration, lifecycle timeout tracking, and dispatch guard hooks. |
| `src/Modules/Access/` | New | Handlers, queries, commands, and credential validators for authentication. |
| `templates/pages/`, `templates/fragments/` | New/Modified | Login view (`pages/login.php`), user status/logout in `templates/layout.php`. |
| `config/routes.php` | Modified | Route definitions for login/logout and route authorization metadata. |
| `scripts/console.php` | Modified | Interactive CLI command for initial administrator bootstrap. |
| `tests/Integration/` | New/Modified | Authentication suite and test authentication helper for existing R1 tests. |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Session fixation attacks | Medium | Mandatory `session_regenerate_id(true)` upon successful credential authentication. |
| Session cookie exposure via XSS | Low | Strict `HttpOnly` and `SameSite=Lax` cookie configuration. |
| Broken UI on expired HTMX requests | Medium | Server responds with `HX-Redirect: /login` to force full window redirection. |
| Lockout recovery without Admin UI | Medium | Document CLI administrative unlock/recovery mechanism during Design. |
| Open redirect vulnerabilities | Low | Restrict post-login redirect targets strictly to validated relative internal paths. |
| Existing R1 test suite disruption | Medium | Implement reusable test helper (`withAuthenticatedUser()`) for integration tests. |

## Rollback Plan

Revert the additive user migration via `MigrationRunner`, dropping the `usuario` table. Remove `src/Modules/Access/`, login templates, and route modifications. The application reverts cleanly to unauthenticated R1 operation.

## Dependencies

- Existing Foundation: `Database`, `Transaction`, `Router`, `Renderer`, `Csrf`, `NativeSession`.
- PHP 8.5 native `password_hash()` and `password_verify()` with `PASSWORD_BCRYPT`.
- Existing R1 inventory and catalog handlers.

## Success Criteria

- [ ] Anonymous access to protected R1 business routes (`/products`, `/locations`, `/inventory`, `/inventory/counts`) is rejected with a redirect to `/login` (browser) or `HX-Redirect` (HTMX).
- [ ] Active registered users authenticate successfully with valid credentials and receive an active session with regenerated ID.
- [ ] Authentication fails safely for invalid credentials, inactive accounts, and accounts locked by security rules.
- [ ] Consecutive failed login attempts (> 5 in 10 minutes) automatically lock the account (`Bloqueado`).
- [ ] Cashier sessions expire after 20 minutes of inactivity.
- [ ] Authenticated users with unauthorized roles receive `403 Forbidden` on direct restricted requests.
- [ ] Logging out invalidates the server session and clears authentication markers.
- [ ] The initial administrator account can be created via an interactive CLI command without hardcoded credentials.
- [ ] All existing R1 catalog, location, stock, and count integration tests pass when authenticated.
- [ ] PHPUnit test suite passes with 0 failures and PHPStan static analysis remains Level max clean.
