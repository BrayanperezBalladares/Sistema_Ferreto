# Access Control Foundation Exploration

## Exploration: access-control-foundation

### Current State
The project has successfully established and archived both its infrastructure foundation (`project-foundation-bootstrap`) and its core business inventory domain (`product-inventory-core`).
The system runs on PHP 8.5.10 with MariaDB via PDO, structured as a lightweight server-rendered modular monolith using Bulma CSS and HTMX without a JavaScript build step.

Currently, all operational endpoints in `config/routes.php` (`/products`, `/categories`, `/locations`, `/inventory`, `/inventory/counts`) are fully accessible anonymously. Anyone with network access to the web server can read product catalogs, alter prices, deactivate/reactivate products, create storage locations, establish initial stock positions, and record observational physical counts.

The current system has no formal mechanism to verify:
1. **WHO** is issuing the HTTP request (Authentication).
2. **WHAT** privileges or roles that entity holds (Authorization).
3. **WHICH** user should be attributed to historical actions or audit trails (Identity attribution).

---

### Authority Hierarchy & Traceability Analysis

#### Authority Order
1. **Official project problem statement / instructor requirements** (`mod_cuentas_accesos.md`, `especificacion_requerimientos_sistema_ferretero.md`, `mod_auditoria_mantenimiento.md`).
2. **Canonical OpenSpec specifications** (`openspec/specs/*`).
3. **Existing implemented architecture** (`src/Foundation/`, `src/Modules/Inventory/`, `public/index.php`, `config/routes.php`).
4. **Existing SDD/archive decisions** (historical change logs in `openspec/changes/archive/`).
5. **Professor POC as REFERENCE ONLY** (`reference/professor-poc/`).

#### Role Definition Authority vs. Permission Authority Audit
- **Roles (SUPPORTED)**: `mod_cuentas_accesos.md` (Sección 2, **RF-02**) and Section 5 (Tabla `USUARIO`) explicitly mandate four distinct roles via CHECK constraint:
  - `administrador`
  - `cajero`
  - `bodeguero`
  - `compras`
- **Role Permission Scopes in Authoritative Sources**:
  - `mod_cuentas_accesos.md` Section 2 (**RF-02**) defines high-level conceptual domains for each role:
    - `Administrador`: *"Acceso completo al sistema, incluyendo auditoría global, respaldos, configuraciones fiscales y reportería estratégica."*
    - `Cajero`: *"Limitado estrictamente a la interfaz del punto de venta (POS), cobros, arqueos y asignación de clientes a ventas."*
    - `Bodeguero`: *"Acceso exclusivo a la recepción física de mercancía, muelle, gestión física de ubicaciones, control de lotes y despacho/recepción de traslados inter-sucursal."*
    - `Compras`: *"Gestión exclusiva de proveedores, costos pactados y automatización/aprobación de órdenes de compra."*
- **Audit Finding on Route-Level Matrix**:
  - The authoritative materials **DO NOT** provide a route-by-route HTTP permission matrix for the currently implemented R1 endpoints (`/products`, `/locations`, `/inventory`, `/inventory/counts`).
  - Authoritative text assigns high-level domains (POS to Cajero, Suppliers to Compras, Dock/Locations to Bodeguero, Full Access to Administrador).
  - Therefore, while the four roles are strictly **SUPPORTED**, the exact assignment of R1 routes to roles (such as read-only access to `/products` for Cajero/Compras, or count recording for Bodeguero) is **DERIVED / maintainer-approved baseline** for R1, not source-mandated HTTP behavior.

#### Requirement Traceability Matrix
From `mod_cuentas_accesos.md` and `especificacion_requerimientos_sistema_ferretero.md`:

| Requirement Code | Description / Domain Intent | Classification | Notes & Exact Source Citation |
|---|---|---|---|
| **RF-01 (MOD_ACCESOS)** | Secure login interface, session management, password recovery. No access to operational screens without active verified session. | **SUPPORTED** (Login/Session)<br>**OPTIONAL** (Password recovery) | `mod_cuentas_accesos.md` §2 RF-01. Password recovery via email/SMS requires external network transport absent from local runtime. |
| **RF-02 (MOD_ACCESOS)** | Role-Based Access Control (RBAC) with explicitly listed roles: `Administrador`, `Cajero`, `Bodeguero`, `Compras`. | **SUPPORTED** (Roles)<br>**DERIVED** (R1 route mapping) | `mod_cuentas_accesos.md` §2 RF-02 explicitly lists the 4 roles. Specific R1 HTTP route access rules are derived. |
| **RF-03 (MOD_ACCESOS)** | Binding operational user accounts (Cashiers, Warehouse staff) to a physical branch (`SUCURSAL.id_sucursal`). | **FUTURE / CONFLICTING** | `mod_cuentas_accesos.md` §2 RF-03. Branch hierarchy (`SUCURSAL`) was explicitly deferred to **R9** in OpenSpec R1 archive. The current schema has no `SUCURSAL` table. Requiring `id_sucursal NOT NULL` now would prematurely force R9 scope. |
| **RNF-01 (MOD_ACCESOS)** | Password hashing via `password_hash()` using bcrypt algorithm. Ban plaintext and reversible encryption (MD5, SHA1). | **SUPPORTED** | `mod_cuentas_accesos.md` §3 RNF-01. Native PHP 8.5 capability (`PASSWORD_BCRYPT` with cost factor 10). |
| **RNF-02 (MOD_ACCESOS)** | Server-side session control with cryptographic tokens and inactivity timeout (20 min for cashiers). | **SUPPORTED** (Server sessions)<br>**DERIVED** (Timeout policy) | `mod_cuentas_accesos.md` §3 RNF-02 mentions cashier 20 min in narrative text; general session timeout policy remains a design/product decision. |
| **RNF-03 (MOD_ACCESOS)** | Input sanitization, PDO prepared statements, client + server validation. | **SUPPORTED** | `mod_cuentas_accesos.md` §3 RNF-03. Follows established Foundation pattern (`Csrf`, `ValidationResult`, PDO parameters). |
| **RF-09 (SRS) / MOD_MANTENIMIENTO** | Immutable audit log (`REGISTRO_ACCION_LOG`) recording user ID, timestamp, IP, table, action, and JSON diffs. | **FUTURE (Dependent)** | `especificacion_requerimientos_sistema_ferretero.md` §2 RF-09; `mod_auditoria_mantenimiento.md` §2 RF-01. Access control provides the authenticated `id_usuario`; audit logging is an R6 module. |
| **Section 6 (MOD_ACCESOS)** | Account lifecycle states: `Creado`, `Activo`, `Bloqueado`, `Inactivo`. Brute-force lock after more than 5 failed attempts in 10 minutes, requiring administrative unlock. | **SUPPORTED** (Core Lifecycle & Lockout Rule)<br>**DERIVED** (Relational Column Mapping) | `mod_cuentas_accesos.md` §6 explicitly mandates the 4 states and the basic lockout rule (>5 failures in 10 min). Advanced rate-limiting/IP throttling is deferred; how this maps to SQL columns is an open Design decision. |
| **Section 8 (MOD_ACCESOS)** | 2FA / MFA (email/SMS), mandatory 90-day password rotation, multi-branch floating workers. | **OPTIONAL / FUTURE** | `mod_cuentas_accesos.md` §8 explicitly classified as "Puntos Pendientes de Definición". |

---

### Account State Model Audit (Blocked vs. Inactive)

A careful audit of `mod_cuentas_accesos.md` reveals an internal structural ambiguity between narrative design and relational schema:
1. **Section 6 (Lifecycle Narrative & State Diagram)** models **4 distinct lifecycle states**:
   - `Creado`: Account pre-registered by HR, waiting for initial employee credential setup.
   - `Activo`: Normal operational state.
   - `Bloqueado`: **Temporary security lockout** triggered automatically upon $> 5$ consecutive failed login attempts within 10 minutes (requires manual Administrator unlock).
   - `Inactivo`: **Permanent administrative deactivation** upon employee termination, preserving referential audit integrity while permanently revoking access.
2. **Section 5 (Relational Table `USUARIO`)** collapses this into a single binary flag:
   - `estado_activo BOOLEAN`: *(1: Activo, 0: Bloqueado/Desactivado)*.

**Audit Conclusion & Recommendation for Proposal/Design**:
- `Bloqueado` (security/brute-force lock) and `Inactivo` (administrative termination) are conceptually distinct in the business lifecycle, even though Section 5 collapsed them into a single boolean for a preliminary POC.
- Collapsing them loses the ability to distinguish an account temporarily locked by failed passwords from an account permanently revoked by HR.
- **For Explore**: We flag this as a **formal unresolved design decision for Proposal/Design**:
  - *Option 1*: Multi-state status column (e.g. `estado VARCHAR(20)` with CHECK constraint: `'activo'`, `'bloqueado'`, `'inactivo'`, `'creado'`).
  - *Option 2*: Baseline binary `estado_activo BOOLEAN` for Foundation, with a separate `bloqueado_hasta DATETIME NULL` or failed attempts counter for security lockout.
- Schema design must NOT be finalized during Explore.

---

### Session Timeout Source Audit

An exact textual audit of timeout specifications across authoritative documents reveals:
- **`mod_cuentas_accesos.md` §3 (RNF-02)**:
  > *"El sistema debe gestionar sesiones de servidor seguras con tokens criptográficos de tiempo limitado. Las sesiones de los cajeros deben expirar automáticamente tras 20 minutos de inactividad para prevenir fraudes en cajas desatendidas."*
- **SRS General (`especificacion_requerimientos_sistema_ferretero.md`)**: Contains no general inactivity timeout rule.
- **Audit Assessment**:
  - The 20-minute value is explicitly restricted in the text to **cajeros** ("las sesiones de los cajeros").
  - The document provides **NO binding numeric timeout** for administrators, warehouse personnel (`bodeguero`), or purchasing staff (`compras`).
  - The 30-minute uniform timeout mentioned in early discussions is an arbitrary convention with zero source backing.
- **Resolution**:
  - The generic requirement is: **"Session expires after a configurable period of inactivity"**.
  - The specific cashier 20-minute policy applies to the future POS module (R2).
  - The exact default session idle timeout for the application remains an **unresolved design/product decision** for Proposal/Design.

---

### Existing Security Baseline & Precise HttpOnly Assessment

- **CSRF Protection**: Handled by `App\Foundation\Csrf` via `Session` token and validated in `Kernel::handle()`. It operates exclusively on state-changing methods (`POST`).
  - *Critical distinction*: CSRF validates that a request originated from the legitimate application origin/form; it provides **zero proof** of user identity or role authorization. Authentication and authorization are independent layers.
- **Session Handling**: `NativeSession` initializes PHP session with `httponly = true`, `samesite = 'Lax'`, and dynamic `secure` flag (true in non-local environments).
- **Corrected HttpOnly Assessment**:
  - `HttpOnly` prevents client-side JavaScript from directly reading the session cookie (`document.cookie`), reducing session-cookie exposure during XSS.
  - `HttpOnly` **does NOT** by itself prevent XSS vulnerabilities, nor does it prevent an attacker's injected script from issuing authenticated requests on behalf of the user through the active browser session.
- **Routing & Dispatch**: `Router` matches method and path, with a `before` callback in `Kernel`. The `before` hook currently checks CSRF. It can be cleanly composed to evaluate authentication and authorization guards prior to executing handler actions.
- **Request / Response**: `Request` detects HTMX via `isHtmx()`. `Response` supports status codes, headers, and safe relative redirects (`Response::redirect()`). For HTMX requests, authentication redirects must support the `HX-Redirect` header to force client-side window redirection rather than swapping the login form into an inner table fragment.
- **Dependencies**: PHP 8.5 native runtime includes `password_hash()`, `password_verify()`, and cryptographically secure random generators (`random_bytes`). No third-party security packages are necessary.

---

### Maintainer Decisions (Approved Baseline for Proposal)

The maintainer has reviewed the exploration and established the following binding directions for Proposal/Design:

#### A. Login Identifier
- **Approved**: Use **`username` only** for the Access Control Foundation.
- Do not introduce `email` as an authentication identifier.
- Aligns strictly with SRS table specification `USUARIO.username VARCHAR(50) UNIQUE` and operational speed in retail terminal environments. Email remains a future user-profile concern if ever required.

#### B. Bootstrap Administrator
- **Approved Direction**: An explicit **interactive CLI command** executed on the server console:
  `php scripts/console.php create-user`
- Requirements:
  - `username` supplied explicitly.
  - `role` supplied explicitly (`administrador`).
  - Password requested interactively without echo (or via secure prompt).
  - Password never committed to version control.
  - Strictly **NO** default `admin:admin` credentials.
  - Strictly **NO** default accounts generated silently by database migrations.
  - Strictly **NO** domain seeds containing fixed credentials.

#### C. Session Architecture
- **Approved Direction**: **Server-side PHP session + secure session cookie** (`HttpOnly`, `SameSite=Lax`, `Secure` in production).
- JWT is **REJECTED** for the current SSR + Bulma + HTMX browser application.
- Exact inactivity timeout value remains a configurable design parameter to be settled in Proposal/Design.

#### D. Cajero Access During R1
- **Approved Direction**: A `cajero` user may have **read-only access** to the product catalog (`GET /products`) while POS (R2) is not yet implemented.
- Strictly no catalog mutation permissions for `cajero`.
- **Classification**: **DERIVED / maintainer-approved direction** (not explicitly mandated by historical SRS, but operationally sound for price lookups prior to POS).

---

### Provisional R1 Access Matrix (Maintainer-Approved DERIVED Baseline)

Based on approved maintainer directions and existing R1 capabilities, this matrix serves as the provisional baseline for Proposal:

| Route | HTTP Method | Operation Type | Sensitivity | Authorized Roles | Classification | Anonymous Allowed? |
|---|---|---|---|---|---|---|
| `/health` | GET | System Diagnostic | Low | Public / Internal (Separate policy) | **DERIVED** | Evaluated separately below |
| `/health` | POST | Foundation Probe | Low | Diagnostic / Internal Probe | **DERIVED** | Evaluated separately below |
| `/products` | GET | READ (Catalog search) | Low | `administrador`, `cajero`, `bodeguero`, `compras` | **DERIVED** (Maintainer approved) | **NO** |
| `/categories` | POST | CREATE (Category) | Medium | `administrador` | **DERIVED** (Maintainer approved) | **NO** |
| `/products` | POST | CREATE (Product) | Medium | `administrador` | **DERIVED** (Maintainer approved) | **NO** |
| `/products/{id}/price` | POST | UPDATE (Selling price) | High | `administrador` | **DERIVED** (Maintainer approved) | **NO** |
| `/products/{id}/deactivate` | POST | STATE CHANGE | High | `administrador` | **DERIVED** (Maintainer approved) | **NO** |
| `/products/{id}/activate` | POST | STATE CHANGE | High | `administrador` | **DERIVED** (Maintainer approved) | **NO** |
| `/locations` | GET | READ (Locations list) | Low | `administrador`, `bodeguero` | **DERIVED** (Maintainer approved) | **NO** |
| `/locations` | POST | CREATE (Location) | Medium | `administrador`, `bodeguero` | **DERIVED** (Maintainer approved) | **NO** |
| `/inventory` | GET | READ (Stock overview) | Medium | `administrador`, `bodeguero` | **DERIVED** (Maintainer approved) | **NO** |
| `/inventory/stock` | POST | CREATE (Stock position) | High | `administrador`, `bodeguero` | **DERIVED** (Maintainer approved) | **NO** |
| `/inventory/counts` | GET | READ (Count history) | Medium | `administrador`, `bodeguero` | **DERIVED** (Maintainer approved) | **NO** |
| `/inventory/counts` | POST | OBSERVATIONAL WRITE | High | `administrador`, `bodeguero` | **DERIVED** (Maintainer approved) | **NO** |

*Core Rule*: All business operational routes require authentication.

---

### Health Endpoint Audit (`/health`)

An audit of [`src/Foundation/HealthHandler.php`](file:///C:/Sistema_Ferreto/src/Foundation/HealthHandler.php) and [`templates/fragments/health.php`](file:///C:/Sistema_Ferreto/templates/fragments/health.php) shows:
1. `GET /health` renders a probe input form and verifies session flash messages.
2. `POST /health` validates CSRF, validates a probe string ($\le 80$ chars), sets a session flash, and emits an HTMX notification or redirect.
3. **Current Purpose**: It was introduced in `project-foundation-bootstrap` as an end-to-end integration harness proving:
   - Router dispatch
   - CSRF token validation
   - Session storage
   - Template rendering (page vs HTMX fragment)
   - Flash messaging
4. **Security Assessment**:
   - `GET /health` exposes no database secrets, queries, or sensitive operational records.
   - `POST /health` requires a valid CSRF token and modifies only the current user's session flash.
5. **Recommendation**:
   - Do not conflate the infrastructure bootstrap probe with an unauthenticated monitoring health check.
   - Whether `/health` remains public for infrastructure liveness probes (e.g. Docker/Kubernetes/load balancer) or should require authentication requires an explicit Design decision in Proposal/Design.

---

### UI Authorization vs. Server Authorization Principle

- **Fundamental Rule**: Hiding a link, button, or modal in the UI is an ergonomic user experience concern, **never security enforcement**.
- Even if a "Cambiar precio" or "Nuevo conteo" button is omitted from the HTML for a Cashier or anonymous user, any direct HTTP POST to `/products/1/price` or `/inventory/counts` must be intercepted and rejected on the server with `403 Forbidden`.
- The presentation layer (`templates/`) conditionally renders actions based on current user roles, while the `Kernel`/`Router`/`Handler` enforces validation independently.

---

### Authentication Failure & HTTP Semantics

The proposed direction for handling unauthenticated and unauthorized requests:
- **Unauthenticated Browser Request (GET to operational page)**:
  - Respond with a redirect to `/login`.
  - The exact redirect status code (`302 Found` vs `303 See Other`) will be finalized in Design in accordance with `Response::redirect()` conventions.
  - Preserve the requested URL (or safe relative path) to redirect back after successful login.
- **Unauthenticated HTMX Request**:
  - Respond with `HX-Redirect: /login` header. This instructs HTMX on the client to perform a full browser window redirect to the login screen, avoiding broken nested UI fragments.
- **Authenticated but Unauthorized Request (Forbidden)**:
  - Respond with `403 Forbidden` and an informative, professional error fragment or page indicating that the user's active role lacks privileges for the requested operation.

---

### Exploration Boundary Recommendation

#### Recommended Scope for Next Change: Option A (Access Control Foundation only)
1. **User Persistence**: Schema migration for `usuario` table (ID, unique username, bcrypt password hash, role, status, timestamps).
2. **Credential Verification**: Native PHP bcrypt `password_verify()` in an isolated command/query module (`src/Modules/Access/` or `src/Modules/Auth/`).
3. **Session Lifecycle**: Login (`POST /login`), logout (`POST /logout`), session ID regeneration upon login, session destruction on logout.
4. **Authentication & Role Guards**: Server-side interceptor in `Kernel`/`Router` enforcing active session and verifying authorized roles before dispatching to handlers.
5. **Authenticated User Context**: Current user identity and role exposed to views, displaying active user in `templates/layout.php` topbar.
6. **Administrator Bootstrap**: Safe CLI command (`scripts/console.php create-user`) for initial account generation without committed secrets.

#### Explicitly Deferred Scope (Out of Scope for this Change)
- **User Administration UI**: CRUD screens, listing tables, user editing modals, role management forms (deferred to a dedicated follow-up change).
- **Password Reset**: Self-service email/SMS password reset workflows.
- **Multi-Factor Authentication (MFA / 2FA)**.
- **OAuth / Social Login / SSO**.
- **Audit Logging Engine (`REGISTRO_ACCION_LOG`)**: Implementation of table change diffs and audit log storage (deferred to R6; depends on this foundation).
- **Branch Association (`SUCURSAL.id_sucursal`)**: Deferred to R9 when branch/warehouse hierarchy is implemented.
- **Enterprise IAM Subsystem**: Dynamic multi-table permission matrices (`usuario_rol`, `rol_permiso`).

---

### Remaining Proposal Decisions (Unresolved for Proposal/Design)

1. **Account Status Schema Representation**:
   - Decide between a multi-state column (`estado VARCHAR(20)` with CHECK: `'activo'`, `'bloqueado'`, `'inactivo'`, `'creado'`) versus a binary flag (`estado_activo BOOLEAN`) accompanied by a lockout timestamp/counter.
2. **Session Inactivity Timeout Duration**:
   - Settle the default idle timeout duration (e.g. 15, 20, or 30 minutes) and determine whether cashier-specific 20-minute timeouts should be enforced globally or deferred until POS (R2).
3. **Health Route Policy**:
   - Determine whether `/health` should remain an unauthenticated infrastructure probe or require authentication in production.
4. **Safe Redirect After Login**:
   - Decide whether to store the intended target URL in session or query parameter (e.g., `/login?return=/inventory`) to return the user to their requested screen post-login.

---

### Ready for Proposal
**YES** — The maintainer decisions are incorporated, source requirements are rigorously classified (distinguishing SUPPORTED roles from DERIVED route mappings), HttpOnly language is corrected, and all ambiguities are documented cleanly.
