# Design: Access Control Foundation

## Technical Approach

Establish the minimal, robust security foundation for **Ferreterías El Constructor** to govern operational access across all R1 endpoints (`/products`, `/categories`, `/locations`, `/inventory`, `/inventory/counts`). The design introduces user account persistence in MariaDB, bcrypt password verification with timing mitigation, server-side session management via extended `NativeSession`, atomic brute-force lockout using a first-failure fixed window, centralized pre-handler authentication and database-authoritative role authorization guards, safe internal return redirection, an industrial-styled responsive login view, and interactive CLI bootstrap/recovery tools—strictly without third-party frameworks, User Admin UI, or premature multi-branch bindings.

---

## Architecture Decisions

### Decision Summary

| Area | Option Chosen | Alternatives Rejected | Rationale |
|---|---|---|---|
| **Account State** | `VARCHAR(20)` + `CHECK` constraint (`creado`, `activo`, `bloqueado`, `inactivo`). Schema default `'creado'`. | Binary `estado_activo` boolean; MySQL `ENUM`; default `'activo'` | Preserves distinct business semantics between administrative deactivation and temporary security lockout without database engine lock-in. Default `'creado'` ensures unconfigured DB inserts cannot authenticate accidentally. |
| **Role Column** | `VARCHAR(30)` + `CHECK` constraint (`administrador`, `cajero`, `bodeguero`, `compras`) | `VARCHAR(20)`; multi-table RBAC; JSON roles | Strictly preserves authoritative schema dimensions from `mod_cuentas_accesos.md` §5 (`rol VARCHAR(30)`). |
| **Password Hashing** | Native PHP `password_hash(..., PASSWORD_BCRYPT, ['cost' => 10])` | Argon2id; custom hashing; reversible encryption | Strictly mandated by `mod_cuentas_accesos.md` §3 RNF-01 ("factor de costo predeterminado de 10"). |
| **Password Policy** | Minimum length = 15 chars, maximum supported $\ge 64$ chars (up to 72 bytes for bcrypt); no mandatory composition rules (no required uppercase, lowercase, numbers, symbols); no periodic rotation | Arbitrary composition complexity (e.g. 1 uppercase + 1 symbol); minimum 8 chars; periodic password expiration | Classified as **PROJECT DESIGN SECURITY BASELINE** (not SRS-derived). Informed by contemporary password security guidance for single-factor authentication (e.g. NIST SP 800-63B) emphasizing length over composition complexity. MFA remains deferred. |
| **Session Model** | Server-side PHP session + secure cookie (`HttpOnly`, `SameSite=Lax`, `Secure`) | JWT; database-backed session table | SSR + HTMX application relies on browser cookie mechanics; avoids JWT revocation complexity and localStorage XSS hazards. |
| **Lockout Window** | First-Failure Fixed Window (10 minutes) | True rolling window; sliding attempt log table | Authoritative text ("más de 5 intentos fallidos en 10 minutos") does not specify sliding mechanics. Fixed window initiated at first failure fulfills domain intent using an economical 2-column model without an attempt-log table. |
| **Lockout Concurrency** | Single atomic conditional SQL `UPDATE` | `SELECT ... FOR UPDATE`; distributed Redis locks | Guarantees atomic counter increments and state transitions without concurrency race conditions or lost increments. MariaDB-specific syntax encapsulated in `UserCommand`. |
| **Timestamp Convention** | `DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP())` | `ON UPDATE CURRENT_TIMESTAMP`; local server time | Conforms to existing project migrations `0001`–`0006`. `UserCommand` explicitly updates `updated_at = UTC_TIMESTAMP()` following repository conventions. |
| **Guard Placement** | Centralized pre-dispatch interceptor via `Kernel` / `Router` | Per-handler manual auth checks; annotations | Guarantees fail-safe enforcement across all routes; prevents bypass via forgotten handler checks. |
| **Current User Authority** | Database lookup on each protected request | Trusting cached session role blindly | `auth_user_id` in session is a pointer; the database row is the sole authority for `estado` and `rol`. Role changes or account locks take effect on the immediate next request. |
| **HTMX Unauth Policy** | Response header `HX-Redirect: /login` with HTTP 200 and empty body | Fragment swap with login form; HTTP 401/303 with header | HTMX reliably executes full window navigation upon receiving `HX-Redirect` on a 200 OK response; avoids nesting full login forms inside tables or modales. |
| **Return Redirection** | Server session storage (`auth_target_url`) with relative path validation | Query parameter (`?return=...`); open referer header | Eliminates open redirect vectors and tamperable URL parameters while preserving workflow continuity. |
| **Admin Provisioning** | Interactive CLI command (`scripts/console.php create-user`) with initial state `'activo'` | DB migration seed; default `admin:admin`; public signup | Zero plaintext credentials in version control; privileged provisioning creates usable accounts before User Admin UI exists. |
| **Lockout Recovery** | Interactive CLI command (`scripts/console.php unlock-user`) | Automatic time-based unlock; email reset link | Authoritative requirement (§6) mandates administrative unlock; avoids external SMTP dependency. Restricts recovery strictly to `bloqueado` accounts. |
| **Health Endpoint** | `GET /health` public minimal liveness; `POST /health` test/dev-only harness | Public mutating probe; fully authenticated health check | Allows container/load-balancer liveness checks while closing arbitrary session mutation in production (`APP_ENV !== 'production'`). |

---

## Data Model & Schema Design

### Migration `0007_create_usuario`

```sql
-- Up migration: 0007_create_usuario.up.sql
CREATE TABLE usuario (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    rol VARCHAR(30) NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'creado',
    failed_attempt_count INT NOT NULL DEFAULT 0,
    failure_window_started_at DATETIME NULL,
    locked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
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
                 (Explicit activo)
                       │
                       ▼
                 [  ACTIVO  ] ◄──────────────── Administrative unlock-user
                  │        │                               │
    6th failure   │        │ Employee                      │
    in 10 min     │        │ departure                     │
                  ▼        ▼                               │
            [ BLOQUEADO ]  [ INACTIVO ]                    │
                  │                                        │
                  └────────────────────────────────────────┘
```

- **`creado`**: Default schema state. Account exists in database; authentication is rejected until explicitly activated. Protects against accidental immediate activation on generic database inserts.
- **`activo`**: Standard operational state. User can authenticate with valid credentials. CLI `create-user` explicitly sets `estado = 'activo'` during controlled initial provisioning.
- **`bloqueado`**: Temporary security lockout triggered automatically on the 6th failed attempt within a 10-minute window. Restorable to `activo` only via CLI `unlock-user`.
- **`inactivo`**: Administrative deactivation (employee departure). Permanently revokes access while preserving referential integrity for audit history. Never unlocked automatically or via `unlock-user`.

---

## Lockout Algorithm & Concurrency

### Exact Sixth-Failure Invariant
The authoritative requirement states lockout occurs after **more than 5** failed attempts within 10 minutes:
- Failure #1 within window $\rightarrow$ `failed_attempt_count = 1`, window starts, account remains `activo`.
- Failure #2 within window $\rightarrow$ `failed_attempt_count = 2`, account remains `activo`.
- Failure #3 within window $\rightarrow$ `failed_attempt_count = 3`, account remains `activo`.
- Failure #4 within window $\rightarrow$ `failed_attempt_count = 4`, account remains `activo`.
- Failure #5 within window $\rightarrow$ `failed_attempt_count = 5`, account remains `activo`.
- Failure #6 within 10 minutes $\rightarrow$ `failed_attempt_count = 6`, account transitions immediately to `bloqueado`, `locked_at` recorded.

### Window Semantics: First-Failure Fixed Window
The authoritative source (`mod_cuentas_accesos.md` §6) specifies *"más de 5 intentos fallidos de contraseña en un lapso de 10 minutos"*. It does not specify sliding/rolling attempt-log mechanics.
- The project Design explicitly implements a **First-Failure Fixed Window**:
  - The first failed attempt initializes `failure_window_started_at = UTC_TIMESTAMP()` and sets `failed_attempt_count = 1`.
  - Subsequent failures arriving within 10 minutes of that recorded start timestamp increment `failed_attempt_count`.
  - If 10 minutes elapse without reaching the 6th failure, the window expires; the next failed attempt discards the old window, resets `failed_attempt_count = 1`, and begins a new 10-minute window.
  - This avoids an auxiliary attempt-log table while enforcing the 10-minute constraint deterministically.

### Concurrency & SQL Portability Boundary
To eliminate race conditions without table locks, failure increments execute via a single atomic conditional `UPDATE`:

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

**Portability Boundary**:
- The current application targets MariaDB OLTP (`spec/transactional-data-foundation`).
- The syntax `DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)` is MariaDB-specific.
- This query is encapsulated strictly inside `UserCommand`. The domain logic and invariants remain engine-agnostic, but this specific SQL implementation is MariaDB-targeted to ensure concurrency correctness without lost increments. If SQL Server support is added in future changes, the query adaptation is isolated to `UserCommand`.

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
- `auth_user_id` (`int`): Primary key of authenticated user (identifies identity to revalidate).
- `auth_username` (`string`): Username for layout display.
- `auth_last_activity` (`int`): Unix timestamp of last authenticated HTTP interaction.
- `auth_target_url` (`string|null`): Validated relative internal destination for post-login return.

*(Note: `auth_role` is not cached as an authoritative security token; role authorization is evaluated from the live database row).*

### Authoritative Current-User & Stale-Session Invalidation
On every protected HTTP request:
1. If `auth_user_id` is missing $\rightarrow$ request is treated as unauthenticated.
2. If `auth_user_id` is present, query database:
   `SELECT id_usuario, username, rol, estado FROM usuario WHERE id_usuario = :id;`
3. Invalidation triggers:
   - User row does not exist.
   - `estado !== 'activo'` (account is `bloqueado`, `inactivo`, or `creado`).
4. Action on invalidation:
   - Clear session state completely.
   - For standard requests: redirect 303 to `/login` with flash message.
   - For HTMX requests: return HTTP 200 with header `HX-Redirect: /login` and empty body.
5. `RoleGuard` evaluates permissions against the fresh `rol` from this database query. Any administrative role change takes effect immediately on the user's next request.

### Inactivity Expiration Policy
- **Cajero**: 20 minutes (1200 seconds) mandatory per `mod_cuentas_accesos.md` §3 RNF-02.
- **Other roles (`administrador`, `bodeguero`, `compras`)**: Configurable default of 30 minutes (1800 seconds) defined in `config/defaults.php` via `SESSION_IDLE_TIMEOUT` and environment variables. (Classified as a PROJECT DESIGN baseline convention).
- **Evaluation**: Checked on every protected request. If `time() - auth_last_activity > timeout`:
  - Complete session invalidation.
  - Standard request: redirect 303 to `/login`.
  - HTMX request: HTTP 200 with `HX-Redirect: /login`.
  - Anonymous `GET /health` and static asset requests do not update `auth_last_activity`.

### Session Fixation Mitigation Ordering
During `POST /login`:
1. Validate CSRF token and non-empty inputs.
2. Query user record by `username`.
3. If user exists: execute `password_verify($password, $user['password_hash'])`.
   If user does NOT exist: execute `password_verify($password, '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ012')` (precomputed dummy bcrypt hash to mitigate timing discrepancies; failed attempts are never incremented for nonexistent users).
4. If authentication fails or `estado !== 'activo'`:
   - If user exists and is active: execute atomic failed-attempt update.
   - Return HTTP 422 with generic copy: *"Credenciales incorrectas o cuenta no autorizada."*
5. If authentication succeeds:
   a. Reset failed attempt counters in database.
   b. Execute `session_regenerate_id(true)` to destroy the old session token and issue a new cryptographic ID.
   c. Write authentication payload (`auth_user_id`, `auth_username`, `auth_last_activity`).
   d. Retrieve and clear `auth_target_url`.
   e. Issue HTTP 303 redirect to validated destination or `/products`.

### Complete Logout Semantics
`POST /logout` executes:
1. Verify CSRF token.
2. `$_SESSION = [];` (clears in-memory session data for the current request).
3. Expire session cookie on client:
   If `ini_get('session.use_cookies')` is true, invoke `setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly'])`.
4. `session_destroy()` (destroys server session file/storage).
5. Issue HTTP 303 redirect to `/login`.

---

## HTTP Routes & Guard Architecture

### Exact R1 Route Inventory & Authorization Matrix

Derived from actual `config/routes.php` (14 existing routes) plus 3 new access routes:

| # | Method | Path | Handler Key | Allowed Roles | Anonymous Allowed? | Operational Notes |
|---|---|---|---|---|---|---|
| 1 | `GET` | `/health` | `health` | Public / All | **YES** | Minimal liveness check |
| 2 | `POST` | `/health` | `health` | Dev/Test only | Gated | Gated by `APP_ENV !== 'production'` |
| 3 | `GET` | `/login` | `access` | Public / All | **YES** | Redirects to `/products` if authenticated |
| 4 | `POST` | `/login` | `access` | Public / All | **YES** | Authenticates credentials |
| 5 | `POST` | `/logout` | `access` | `administrador`, `bodeguero`, `cajero`, `compras` | **NO** | Complete session destruction |
| 6 | `GET` | `/products` | `catalog` | `administrador`, `bodeguero`, `cajero`, `compras` | **NO** | Read-only catalog search |
| 7 | `POST` | `/categories` | `catalog` | `administrador` | **NO** | Category creation |
| 8 | `POST` | `/products` | `catalog` | `administrador` | **NO** | Product registration |
| 9 | `POST` | `/products/{id}/price` | `catalog` | `administrador` | **NO** | Selling price update |
| 10 | `POST` | `/products/{id}/deactivate` | `catalog` | `administrador` | **NO** | Product deactivation |
| 11 | `POST` | `/products/{id}/activate` | `catalog` | `administrador` | **NO** | Product activation |
| 12 | `GET` | `/locations` | `location` | `administrador`, `bodeguero` | **NO** | Storage locations overview |
| 13 | `POST` | `/locations` | `location` | `administrador`, `bodeguero` | **NO** | Location creation |
| 14 | `GET` | `/inventory` | `inventory` | `administrador`, `bodeguero` | **NO** | Stock positions overview |
| 15 | `POST` | `/inventory/stock` | `inventory` | `administrador`, `bodeguero` | **NO** | Stock position creation *(Notice: POST /inventory does NOT exist)* |
| 16 | `GET` | `/inventory/counts` | `inventory` | `administrador`, `bodeguero` | **NO** | Physical counts overview |
| 17 | `POST` | `/inventory/counts` | `inventory` | `administrador`, `bodeguero` | **NO** | Observational count record |

### Guard Interceptor Flow

```
Request ──→ Router Match
                 │
                 ▼
          [ Auth Guard ] ──── Unauthenticated? ──── Standard: 303 Redirect to /login (remember target)
                 │                                   HTMX: HTTP 200 with HX-Redirect: /login
                 ▼
          [ Inactivity Check ] ── Expired? ──────── Invalidate session & 303 Redirect / HX-Redirect
                 │
                 ▼
          [ DB State Check ] ──── Stale/Blocked? ── Invalidate session & 303 Redirect / HX-Redirect
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

### Return-After-Login Algorithm
- When unauthenticated request targets a protected GET route, validate that the path is an application-internal relative path:
  `str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_starts_with($path, '/\\') && !str_contains($path, '\\')`
- Store validated relative path in `$_SESSION['auth_target_url']`.
- Upon successful login:
  - If `auth_target_url` exists and the user's freshly verified database role is authorized for that path, redirect 303 to `auth_target_url`.
  - If user's role is not authorized for that path, discard target and redirect 303 to default `/products`.
  - Clear `auth_target_url`.

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
- **Initial State**: Explicitly created as `'activo'`. Controlled administrative provisioning creates usable operational principals directly, reconciling with the database schema default (`'creado'`).
- **Password Contract**:
  - **Policy**:
    - Minimum length: 15 characters.
    - Supported maximum: at least 64 characters (up to 72 bytes for bcrypt).
    - Composition requirements: None (no mandatory uppercase, lowercase, numbers, or symbols).
    - Expiration: No periodic password rotation.
  - **Classification**: **PROJECT DESIGN SECURITY BASELINE** (informed by contemporary password security guidance such as NIST SP 800-63B emphasizing length over composition complexity for single-factor authentication). Not an SRS-derived requirement.
  - Prompts interactively on console without terminal echo.
  - Windows: Invokes PowerShell `Read-Host -AsSecureString` wrapper via pipe without exposing plaintext.
  - POSIX: Invokes `stty -echo`, reads input from `STDIN`, restores `stty echo`.
  - **Fail-Closed**: If secure no-echo terminal input cannot be established, the command fails closed with an error. It NEVER falls back to echoed plaintext.
- **Security**: Hashes password using bcrypt (cost 10); never passes passwords via command arguments or logs.

### Security Unlock Command: `unlock-user`
```bash
php scripts/console.php unlock-user <username>
```
- **Requirements**: Username must exist and currently have `estado = 'bloqueado'`.
- **Effects**: Sets `estado = 'activo'`, resets `failed_attempt_count = 0`, `failure_window_started_at = NULL`, `locked_at = NULL`, and sets `updated_at = UTC_TIMESTAMP()`.
- **Invariants**: Strictly refuses to modify accounts with `estado = 'inactivo'` (administrative deactivation) or `estado = 'creado'`.

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
        ├── Authenticator.php     (Credential verification, timing mitigation & lockout orchestration)
        ├── UserQuery.php         (Read-only DB queries: findByUsername, findById)
        ├── UserCommand.php       (DB writes: create, recordFailure, resetFailures, unlock)
        ├── AuthGuard.php         (Authentication & session validation interceptor)
        └── RoleGuard.php         (Route authorization matrix evaluator)
```

---

## Testing & Verification Strategy

### Test Authentication Helper (`tests/Integration/HttpTestTrait.php`)
- Introduce `withAuthenticatedUser(string $role = 'administrador'): void` in test setups.
- Helper inserts an isolated test user into the test database (`_test`) and configures memory session authentication markers before dispatching requests.
- Real guards remain active in `APP_ENV=test`.
- Development database remains strictly untouched and verified via `DatabaseIsolationTrait`.

### Planned Test Matrix
1. **Credential Verification**: Valid login, bad password, non-existent user (constant time dummy hash verification).
2. **Lockout Enforcement**: Consecutive failures 1–5 maintain `activo`; 6th locks account; 11th minute resets window.
3. **Session Security**: Session regeneration on login, complete cookie clearing on logout.
4. **Inactivity Expiration**: Cashier session expires at 20 min; admin at 30 min.
5. **Route Authorization**: Matrix testing across all 14 R1 routes and all 4 roles.
6. **HTMX Integration**: Unauthenticated HTMX request emits HTTP 200 with `HX-Redirect: /login`.
7. **CLI Tools**: Unit/integration tests for `create-user` (validating length $\ge 15$, no composition requirement, no-echo fail-closed, and `activo` creation) and `unlock-user`.

---

## Security Threat Check

| Threat | Mitigation in Design | Residual Risk |
|---|---|---|
| **Brute-Force / Credential Stuffing** | Automatic lockout after 6 failures in 10 minutes; bcrypt cost 10 slows offline cracking. Minimum password length $\ge 15$ characters. | Distributed multi-account low-rate stuffing requires future IP-level rate limiting (R6/Infrastructure). |
| **Account Enumeration (Timing & Copy)** | Generic login failure copy ("Credenciales incorrectas o cuenta no autorizada"). Nonexistent usernames execute dummy bcrypt `password_verify` to equalize response time. | Micro-timing variations at database query level; negligible in internal retail application. |
| **Session Fixation** | Mandatory `session_regenerate_id(true)` upon successful authentication before setting identity keys. | None within application boundary. |
| **Session Hijacking / Theft** | `HttpOnly`, `SameSite=Lax`, and `Secure` cookie attributes. Inactivity timeouts. | Client machine physical theft mitigated by 20-minute cashier timeout. |
| **Stale Session Rights** | Guard checks `usuario` record in database on protected requests; instant revocation on lock/deactivation. | Minimal indexed query overhead on protected requests. |
| **Cross-Site Request Forgery (CSRF)** | Native `Csrf` token validation on all state-changing endpoints (`POST /login`, `POST /logout`, R1 actions). | None; CSRF is fully established in Foundation. |
| **Open Redirects** | Post-login redirect target strictly validated against relative application paths starting with single `/`. | None; external URLs and `//` protocol-relative paths are rejected. |
| **Privilege Escalation** | Server-side `RoleGuard` evaluates live database role on every protected request. | None; role matrix is statically defined and enforced server-side. |
| **CLI Credential Leakage** | Interactive hidden password prompts; credentials never passed as CLI flags; fail-closed if no-echo fails. | Console screen observation mitigated by hidden input. |

---

## Implementation Slicing Guidance (Review Budget <= 400 Lines/Commit)

1. **Slice A (Schema & Persistence)**: Migration `0007_create_usuario`, `UserQuery`, `UserCommand`.
2. **Slice B (CLI Provisioning & Recovery)**: `create-user` (enforcing $\ge 15$ chars) and `unlock-user` CLI commands in `Console.php`.
3. **Slice C (Authentication & Session Foundation)**: Extended `NativeSession`, `Authenticator`, `AccessHandler` (`/login`, `/logout`), login view.
4. **Slice D (Guards & Route Protection)**: `AuthGuard`, `RoleGuard`, `Kernel` interceptor integration, R1 route protection.
5. **Slice E (UI Context & Polish)**: `templates/layout.php` topbar user context, conditional action rendering, responsive styling.
6. **Slice F (Test Migration & Quality Gate)**: Test authentication helper, R1 test suite migration, full regression suite.