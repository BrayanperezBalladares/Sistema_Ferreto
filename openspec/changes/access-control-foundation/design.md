# Design: Access Control Foundation

## Technical Approach

Establish the minimal, robust security foundation for **Ferreterías El Constructor** to govern operational access across all R1 endpoints (`/products`, `/categories`, `/locations`, `/inventory`, `/inventory/counts`). The design introduces user account persistence in MariaDB, bcrypt password verification, server-side session management via extended `NativeSession`, atomic brute-force lockout, centralized pre-handler authentication and role authorization guards, safe internal return redirection, an industrial-styled responsive login view, and interactive CLI bootstrap/recovery tools—strictly without third-party frameworks, User Admin UI, or premature multi-branch bindings.

---

## Architecture Decisions

### Decision Summary

| Area | Option Chosen | Alternatives Rejected | Rationale |
|---|---|---|---|
| **Account State** | `VARCHAR(20)` + `CHECK` constraint (`creado`, `activo`, `bloqueado`, `inactivo`) | Binary `estado_activo` boolean; MySQL `ENUM` | Preserves distinct business semantics between administrative deactivation and temporary security lockout without database engine lock-in. |
| **User Persistence** | Dedicated `usuario` table with single role column | Multi-table RBAC (`usuario_rol`, `rol_permiso`); JSON roles | Meets authoritative R1/R2 requirements without over-engineering dynamic permission matrices. |
| **Password Hashing** | Native PHP `password_hash(..., PASSWORD_BCRYPT, ['cost' => 10])` | Argon2id; custom hashing; reversible encryption | Strictly mandated by `mod_cuentas_accesos.md` §3 RNF-01 ("factor de costo predeterminado de 10"). |
| **Session Model** | Server-side PHP session + secure cookie (`HttpOnly`, `SameSite=Lax`, `Secure`) | JWT; database-backed session table | SSR + HTMX application relies on browser cookie mechanics; avoids JWT revocation and localStorage XSS hazards. |
| **Lockout Concurrency** | Single atomic conditional SQL `UPDATE` | `SELECT ... FOR UPDATE`; distributed Redis locks | Guarantees atomic counter increments and state transitions without concurrency race conditions or external infrastructure. |
| **Guard Placement** | Centralized pre-dispatch interceptor via `Kernel` / `Router` | Per-handler manual auth checks; annotations | Guarantees fail-safe enforcement across all routes; prevents bypass via forgotten handler checks. |
| **HTMX Unauth Policy** | Response header `HX-Redirect: /login` with empty body | Fragment swap with login form; standard 303 redirect | Prevents HTMX from nesting full login pages inside table rows, modal dialogs, or sub-containers. |
| **Return Redirection** | Server session storage (`auth_target_url`) with relative path validation | Query parameter (`?return=...`); open referer header | Eliminates open redirect vectors and tamperable URL parameters while preserving workflow continuity. |
| **Admin Provisioning** | Interactive CLI command (`scripts/console.php create-user`) | DB migration seed; default `admin:admin`; public signup | Zero plaintext credentials in version control; secure operational bootstrap before Admin UI exists. |
| **Lockout Recovery** | Interactive CLI command (`scripts/console.php unlock-user`) | Automatic time-based unlock; email reset link | Authoritative requirement (§6) mandates administrative unlock; avoids external SMTP dependency. |
| **Health Endpoint** | `GET /health` public minimal liveness; `POST /health` test/dev-only harness | Public mutating probe; fully authenticated health check | Allows container/load-balancer liveness checks while closing arbitrary session mutation in production. |

---

## Data Model & Schema Design

### Migration `0007_create_usuario`

```sql
-- Up migration: 0007_create_usuario.up.sql
CREATE TABLE usuario (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    rol VARCHAR(20) NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'activo',
    failed_attempt_count INT NOT NULL DEFAULT 0,
    failure_window_started_at DATETIME NULL,
    locked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_usuario_username UNIQUE (username),
    CONSTRAINT chk_usuario_rol CHECK (rol IN ('administrador', 'cajero', 'bodeguero', 'compras')),
    CONSTRAINT chk_usuario_estado CHECK (estado IN ('creado', 'activo', 'bloqueado', 'inactivo'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Down migration: 0007_create_usuario.down.sql
DROP TABLE IF EXISTS usuario;
```

### Account Lifecycle States

```
                 CLI create-user
                       │
                       ▼
                 [  ACTIVO  ] ◄──────────────── Administrative unlock-user
                  │        │                               │
    >5 failures   │        │ Employee                      │
    in 10 min     │        │ departure                     │
                  ▼        ▼                               │
            [ BLOQUEADO ]  [ INACTIVO ]                    │
                  │                                        │
                  └────────────────────────────────────────┘
```

- **`creado`**: Account pre-registered by administrative workflow; authentication rejected until explicitly activated. (Supported for future expansion).
- **`activo`**: Standard operational state. User can authenticate with valid credentials. CLI bootstrap accounts start as `activo`.
- **`bloqueado`**: Temporary security lockout triggered automatically on the 6th failed attempt within a 10-minute window. Restorable to `activo` only via CLI `unlock-user`.
- **`inactivo`**: Administrative deactivation (employee departure). Permanently revokes access while preserving referential integrity for audit history. Never unlocked automatically or via `unlock-user`.

---

## Lockout Algorithm & Concurrency

### Authoritative Rule Interpretation
Lockout occurs after **more than 5** failed attempts in 10 minutes:
- Failed attempts 1 through 5 within 10 minutes: Increment counter, account remains `activo`.
- 6th failed attempt within 10 minutes: Account transitions immediately to `bloqueado`, `locked_at` recorded.
- Failure after 10-minute window elapsed: Previous window discarded, count resets to 1, window starts anew.

### Atomic Concurrency Execution
To prevent race conditions during simultaneous login attempts without heavy table locking, execution uses an atomic conditional `UPDATE`:

```sql
UPDATE usuario
SET
    failed_attempt_count = CASE
        WHEN failure_window_started_at IS NULL
             OR failure_window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)
        THEN 1
        ELSE failed_attempt_count + 1
    END,
    failure_window_started_at = CASE
        WHEN failure_window_started_at IS NULL
             OR failure_window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)
        THEN UTC_TIMESTAMP()
        ELSE failure_window_started_at
    END,
    estado = CASE
        WHEN (failure_window_started_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)
              AND failed_attempt_count >= 5)
        THEN 'bloqueado'
        ELSE estado
    END,
    locked_at = CASE
        WHEN (failure_window_started_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)
              AND failed_attempt_count >= 5)
        THEN UTC_TIMESTAMP()
        ELSE locked_at
    END,
    updated_at = UTC_TIMESTAMP()
WHERE username = :username AND estado = 'activo';
```

On successful authentication:
```sql
UPDATE usuario
SET failed_attempt_count = 0,
    failure_window_started_at = NULL,
    locked_at = NULL,
    updated_at = UTC_TIMESTAMP()
WHERE id_usuario = :id_usuario;
```

---

## Session Lifecycle & Security

### Session State Attributes
Stored in server-side session via `NativeSession`:
- `auth_user_id` (`int`): Primary key of authenticated user.
- `auth_username` (`string`): Username for display and context.
- `auth_role` (`string`): Active role (`administrador`, `cajero`, `bodeguero`, `compras`).
- `auth_last_activity` (`int`): Unix timestamp of last authenticated HTTP interaction.
- `auth_target_url` (`string|null`): Validated relative internal destination for post-login return.

### Session Fixation Mitigation Ordering
During `POST /login`:
1. Receive and validate CSRF token and credentials format.
2. Query user record by `username`.
3. Verify password via `password_verify($password, $user['password_hash'])`.
4. Validate account `estado === 'activo'`.
5. If invalid/blocked/inactive: Execute failure counter update, return 422 with safe generic feedback.
6. If valid:
   a. Reset failed attempt counters in database.
   b. Invoke `session_regenerate_id(true)` to destroy old session ID and issue a new cryptographic token.
   c. Write authentication payload (`auth_user_id`, `auth_username`, `auth_role`, `auth_last_activity`).
   d. Clear `auth_target_url` from session after retrieving it.
   e. Issue 303 redirect to destination.

### Inactivity Expiration Policy
- **Cajero**: 20 minutes (1200 seconds) mandatory per `mod_cuentas_accesos.md` §3 RNF-02.
- **Other roles (`administrador`, `bodeguero`, `compras`)**: Configurable default of 30 minutes (1800 seconds) defined in `config/defaults.php` via `SESSION_IDLE_TIMEOUT`.
- **Enforcement**: Evaluated on every protected request. If `time() - auth_last_activity > timeout`:
  - Invalidate session via `session_destroy()`.
  - Redirect browser to `/login` with notification, or return `HX-Redirect: /login` for HTMX.
  - Public `GET /health` and static assets do not update `auth_last_activity`.

### Stale Session DB Validation
To prevent de-authenticated or blocked accounts from continuing active sessions, the authentication guard performs a lightweight indexed lookup:
`SELECT estado, rol FROM usuario WHERE id_usuario = :id_usuario;`
If `estado !== 'activo'` or role has changed, the session is destroyed immediately and redirected to `/login`.

---

## HTTP Routes & Guard Architecture

### Routes Configuration

```php
// config/routes.php additions
['GET', '/login', 'access'],
['POST', '/login', 'access'],
['POST', '/logout', 'access'],
```

### R1 Route Authorization Matrix

| Method | Path | Handler | Allowed Roles | Anonymous | Notes |
|---|---|---|---|---|---|
| `GET` | `/health` | `health` | Public / All | **YES** | Liveness probe only |
| `POST` | `/health` | `health` | Dev/Test only | Gated | Gated by `APP_ENV !== 'production'` |
| `GET` | `/login` | `access` | Anonymous / All | **YES** | Redirects to `/products` if authenticated |
| `POST` | `/login` | `access` | Anonymous / All | **YES** | Authenticates & establishes session |
| `POST` | `/logout` | `access` | Authenticated | **NO** | Invalidates session & redirects |
| `GET` | `/products` | `catalog` | `administrador`, `bodeguero`, `cajero`, `compras` | **NO** | Read-only catalog search |
| `POST` | `/categories` | `catalog` | `administrador` | **NO** | Category creation |
| `POST` | `/products` | `catalog` | `administrador` | **NO** | Product registration |
| `POST` | `/products/{id}/price` | `catalog` | `administrador` | **NO** | Price update |
| `POST` | `/products/{id}/deactivate` | `catalog` | `administrador` | **NO** | Product deactivation |
| `POST` | `/products/{id}/activate` | `catalog` | `administrador` | **NO** | Product activation |
| `GET` | `/locations` | `location` | `administrador`, `bodeguero` | **NO** | Location overview |
| `POST` | `/locations` | `location` | `administrador`, `bodeguero` | **NO** | Location creation |
| `GET` | `/inventory` | `inventory` | `administrador`, `bodeguero` | **NO** | Stock overview |
| `POST` | `/inventory/stock` | `inventory` | `administrador`, `bodeguero` | **NO** | Stock position creation |
| `GET` | `/inventory/counts` | `inventory` | `administrador`, `bodeguero` | **NO** | Physical count overview |
| `POST` | `/inventory/counts` | `inventory` | `administrador`, `bodeguero` | **NO** | Observational count record |

### Guard Flow in Kernel

```
Request ──→ Router Path Match
                 │
                 ▼
          [ Auth Guard ] ──── Unauthenticated? ──── Standard: 303 Redirect to /login (remember target)
                 │                                   HTMX: Response(200, ['HX-Redirect' => '/login'])
                 ▼
          [ Inactivity Check ] ── Expired? ──────── Invalidate session & Redirect to /login
                 │
                 ▼
          [ DB State Check ] ──── Inactive/Blocked? Invalidate session & Redirect to /login
                 │
                 ▼
          [ Role Guard ] ──────── Unauthorized? ─── Standard: 403 Forbidden Page
                 │                                   HTMX: 403 Forbidden Fragment
                 ▼
          [ CSRF Guard ] ──────── Invalid POST? ─── 403 Forbidden
                 │
                 ▼
           Handler::handle()
```

---

## UI Integration & Responsive Design

### Layout Shell Context (`templates/layout.php`)
- **Topbar**: Displays authenticated user greeting and role badge:
  `<span class="user-name">brayan</span> <span class="badge-role">Administrador</span>`
- **Logout Form**: Accessible touch-safe button ($\ge 44\text{px}$) in topbar and mobile drawer:
  `<form method="post" action="/logout"><input type="hidden" name="_csrf" value="..."><button type="submit" class="btn-logout">Cerrar sesión</button></form>`
- **Conditional Visibility**:
  - `cajero` & `compras`: UI suppresses "Registrar producto", "Nueva categoría", and row actions ("Actualizar precio", "Desactivar", "Activar").
  - `bodeguero`: UI suppresses catalog mutation controls, but retains location, stock, and count creation controls.

### Login Screen Specifications (`templates/pages/login.php`)
- Follows `docs/ui/DESIGN.md`: Light neutral workspace (`#F8FAFC`), deep charcoal brand header (`#111827`), restrained amber primary CTA (`#F59E0B`).
- Centered card container (`max-width: 400px; border-radius: 8px; border: 1px solid #E5E7EB;`).
- Explicit `<label>` elements above inputs with `id` linkage.
- Password input uses `autocomplete="current-password"`. Username input uses `autocomplete="username"`.
- Error messages use `--color-danger-text` (`#991B1B`) on `--color-danger-subtle` (`#FEE2E2`).
- Generic error copy: *"Credenciales incorrectas o cuenta no autorizada."* (avoids user enumeration).
- Touch targets $\ge 44\text{px}$, responsive down to 360px viewport.

---

## CLI Management Tools

### Bootstrap Command: `create-user`
```bash
php scripts/console.php create-user <username> <rol>
```
- **Arguments**: Username (`VARCHAR(50)`), Role (`administrador`, `cajero`, `bodeguero`, `compras`).
- **Interactive Password**: Prompts on console without echo:
  - Windows: Uses PowerShell hidden input (`Read-Host -AsSecureString` wrapper).
  - Unix: Disables terminal echo via `stty -echo`.
  - Fallback: Validated non-empty console prompt.
- **Validation**: Role must be in allowed list; username must not exist; password must be non-empty ($\ge 8$ chars).
- **Initial State**: Account is created directly as `activo`.
- **Security**: Hashes password using bcrypt (cost 10); never echoes or logs plaintext passwords.

### Security Unlock Command: `unlock-user`
```bash
php scripts/console.php unlock-user <username>
```
- **Requirements**: Username must exist and currently have `estado = 'bloqueado'`.
- **Effects**: Sets `estado = 'activo'`, resets `failed_attempt_count = 0`, `failure_window_started_at = NULL`, `locked_at = NULL`.
- **Invariants**: Refuses to modify accounts with `estado = 'inactivo'` or `'creado'`.

---

## Module Boundary & Class Structure

```
src/
├── Foundation/
│   ├── NativeSession.php      (Extends with regenerate, destroy, get/set)
│   ├── Kernel.php             (Dispatches through auth & role guards)
│   └── Console.php            (Routes create-user and unlock-user)
└── Modules/
    └── Access/
        ├── AccessHandler.php     (HTTP handler for GET/POST login, POST logout)
        ├── Authenticator.php     (Credential verification & lockout orchestration)
        ├── UserQuery.php         (Read-only DB queries: findByUsername, findById)
        ├── UserCommand.php       (DB writes: create, recordFailure, resetFailures, unlock)
        ├── AuthGuard.php         (Authentication & session validation interceptor)
        └── RoleGuard.php         (Route authorization matrix evaluator)
```

---

## Testing & Verification Strategy

### Test Authentication Helper (`tests/Integration/HttpTestTrait.php`)
To ensure existing R1 tests (`CatalogHttpTest`, `InventoryHttpTest`, `LocationHttpTest`, `CountHttpTest`, `ResponsiveHttpTest`) run against real production guards without disabling authentication in `APP_ENV=test`:
- Introduce `withAuthenticatedUser(string $role = 'administrador'): void` in test setups.
- Helper inserts an isolated test user into the test database (`_test`) and configures mock/memory session authentication markers before dispatching requests.
- Development database remains strictly untouched and verified via `DatabaseIsolationTrait`.

### Planned Test Matrix
1. **Credential Verification**: Valid login, bad password, non-existent user (constant time / generic error).
2. **Lockout Enforcement**: Consecutive failures 1–5 succeed in failure increment; 6th locks account; 11th minute resets window.
3. **Session Security**: Session regeneration on login, session destruction on logout, cookie security flags.
4. **Inactivity Expiration**: Cashier session expires at 20 min; admin at 30 min.
5. **Route Authorization**: Matrix testing across all roles (e.g. `cajero` accessing POST `/products` receives 403).
6. **HTMX Integration**: Unauthenticated HTMX request emits `HX-Redirect: /login`.
7. **CLI Tools**: Unit/integration tests for `create-user` and `unlock-user`.

---

## Security Threat Check

| Threat | Mitigation in Design | Residual Risk |
|---|---|---|
| **Brute-Force / Credential Stuffing** | Automatic lockout after 6 failures in 10 minutes; bcrypt cost 10 slows offline cracking. | Distributed multi-account low-rate stuffing requires future IP-level rate limiting (R6/Infrastructure). |
| **Account Enumeration** | Generic login failure copy ("Credenciales incorrectas o cuenta no autorizada") for bad password and non-existent username. | Slight timing discrepancy between user lookup and bcrypt hashing; negligible in internal retail application. |
| **Session Fixation** | Mandatory `session_regenerate_id(true)` upon successful authentication before setting identity keys. | None within application boundary. |
| **Session Hijacking / Theft** | `HttpOnly`, `SameSite=Lax`, and `Secure` cookie attributes. Inactivity timeouts. | Client machine physical theft mitigated by 20-minute cashier timeout. |
| **Stale Session Rights** | Guard checks `usuario` record in database on protected requests; instant revocation on lock/deactivation. | Minimal database query overhead on protected requests. |
| **Cross-Site Request Forgery (CSRF)** | Native `Csrf` token validation on all state-changing endpoints (`POST /login`, `POST /logout`, R1 actions). | None; CSRF is fully established in Foundation. |
| **Open Redirects** | Post-login redirect target strictly validated against relative application paths starting with single `/`. | None; external URLs and `//` protocol-relative paths are rejected. |
| **Privilege Escalation** | Strict server-side `RoleGuard` evaluated before handler execution regardless of UI button visibility. | None; role matrix is statically defined and enforced server-side. |
| **CLI Credential Leakage** | Interactive hidden password prompts; credentials never passed as CLI flags or committed to repo. | Console screen observation mitigated by hidden input. |

---

## Implementation Slicing Guidance (Review Budget <= 400 Lines/Commit)

1. **Slice A (Schema & Persistence)**: Migration `0007_create_usuario`, `UserQuery`, `UserCommand`.
2. **Slice B (CLI Provisioning & Recovery)**: `create-user` and `unlock-user` CLI commands in `Console.php`.
3. **Slice C (Authentication & Session Foundation)**: Extended `NativeSession`, `Authenticator`, `AccessHandler` (`/login`, `/logout`), login view.
4. **Slice D (Guards & Route Protection)**: `AuthGuard`, `RoleGuard`, `Kernel` interceptor integration, R1 route protection.
5. **Slice E (UI Context & Polish)**: `templates/layout.php` topbar user context, conditional action rendering, responsive styling.
6. **Slice F (Test Migration & Quality Gate)**: Test authentication helper, R1 test suite migration, full regression suite.