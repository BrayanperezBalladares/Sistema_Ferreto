# Architecture Guide — Sistema Ferreto

> **Audience**: AI Coding Agents & System Architects
> **Pattern**: Modular Monolith, Command-Query Separation (CQS), Server-Rendered HTML-over-the-Wire

---

## 1. Request Lifecycle Pipeline

Every HTTP request enters via `public/index.php` and executes through a deterministic, strictly-ordered pipeline orchestrated by `App\Foundation\Kernel`:

```
+----------------------------------------------------------------------------------------------------+
|                                           CLIENT BROWSER                                           |
+----------------------------------------------------------------------------------------------------+
                                                  |
                                                  v
                                          public/index.php
                                                  |
                                                  v
                                         App\Foundation\Kernel
                                                  |
     +--------------------------------------------+--------------------------------------------+
     | 1. Health Boundary Gate                                                                 |
     |    App\Foundation\HealthAccessPolicy                                                    |
     |    - Allows GET /health (public liveness)                                               |
     |    - Restricts POST /health to development / test (405 Method Not Allowed otherwise)   |
     +--------------------------------------------+--------------------------------------------+
                                                  |
     +--------------------------------------------+--------------------------------------------+
     | 2. Authentication Guard                                                                 |
     |    App\Modules\Access\AuthGuard                                                         |
     |    - Resolves principal via App\Modules\Access\AuthSession                              |
     |    - Enforces role-based inactivity timeouts (20m cajero, 30m others)                   |
     |    - If unauthenticated on protected route: returns 303 Redirect to /login              |
     +--------------------------------------------+--------------------------------------------+
                                                  |
     +--------------------------------------------+--------------------------------------------+
     | 3. Authorization Guard                                                                  |
     |    App\Modules\Access\RoleGuard                                                         |
     |    - Consults canonical App\Modules\Access\RouteAccessPolicy                            |
     |    - If role lacks permission for (Method, Path): returns 403 Forbidden                 |
     +--------------------------------------------+--------------------------------------------+
                                                  |
     +--------------------------------------------+--------------------------------------------+
     | 4. CSRF Guard                                                                           |
     |    App\Foundation\Csrf                                                                  |
     |    - Verifies _csrf token on all state-mutating requests (POST, PUT, DELETE)            |
     |    - If invalid or missing: returns 403 Forbidden                                       |
     +--------------------------------------------+--------------------------------------------+
                                                  |
     +--------------------------------------------+--------------------------------------------+
     | 5. Routing & Handler Dispatch                                                           |
     |    App\Foundation\Router                                                                |
     |    - Matches exact path or parameterized regex pattern (e.g. /products/{id}/price)      |
     |    - Dispatches to matched Handler closure                                              |
     +--------------------------------------------+--------------------------------------------+
                                                  |
                                                  v
                                          Matched Handler
                               (CatalogHandler, StockHandler, etc.)
                                                  |
                         +------------------------+------------------------+
                         |                                                 |
                         v                                                 v
                 Read Operation                                    Write Operation
            (App\Modules\*\Query)                             (App\Modules\*\Command)
                         |                                                 |
                         v                                                 v
               App\Foundation\Database                         App\Foundation\Transaction
                         |                                                 |
                         +------------------------+------------------------+
                                                  |
                                                  v
                                           MariaDB 10.4+
                                                  |
                                                  v
                                        Renderer / ViewContext
                                                  |
                                                  v
                                         Client Response (HTML)
```

---

## 2. Rendering & ViewContext Pipeline

The presentation layer avoids bloated templating engines, relying on lightweight native PHP templates with strict output escaping:

```
App\Foundation\Kernel
        |
        | (populates authenticated user, csrf token, and ViewPermissions)
        v
App\Foundation\ViewContext
        |
        | (injected into)
        v
App\Foundation\Renderer
        |
        +---> templates/layout.php (Application Shell: Topbar, Sidebar, Nav)
                    |
                    +---> templates/pages/<page>.php (Page Header, Search Toolbar, Modals)
                                |
                                +---> templates/fragments/<fragment>.php (Table, Empty States)
```

### Output Escaping Mandate
- **NEVER** output raw user variables directly in templates: `<?= $user_input ?>` is **FORBIDDEN**.
- **ALWAYS** escape using `App\Foundation\Renderer::escape($value)`.
- Numeric values must use tabular numeric styling: `font-variant-numeric: tabular-nums`.

---

## 3. Authorization Flow & Fail-Closed UI

Authorization is governed by a **single canonical authority**:

```
                              RouteAccessPolicy
                          (config/routes.php matrix)
                                    |
                 +------------------+------------------+
                 |                                     |
                 v                                     v
       Server Enforcement                      UI Presentation
        (RoleGuard: 403)                      (ViewPermissions)
                                                       |
                                                       v
                                            Template Helper: $can()
                                                       |
                          +----------------------------+----------------------------+
                          | If ViewPermissions exists: | If ViewPermissions is null |
                          | Queries RouteAccessPolicy  | or invalid:                |
                          | return $policy->isAllowed  | FAILS CLOSED (return false)|
                          +----------------------------+----------------------------+
```

### Template Usage Pattern
Every protected template extracts `$can` safely:
```php
/** @var \App\Modules\Access\ViewPermissions|null $permissions */
$permissions = $data['permissions'] ?? null;
$can = $permissions instanceof \App\Modules\Access\ViewPermissions
    ? $permissions->can(...)
    : static fn (string $method, string $path): bool => false; // STRICT FAIL-CLOSED

// Conditionally render action button:
<?php if ($can('POST', '/products')): ?>
    <button class="btn-primary" data-modal-open="modal-product">+ Registrar producto</button>
<?php endif; ?>
```

---

## 4. Command-Query Separation (CQS) Pattern

All domain data access is decoupled into single-responsibility classes:

### Query Classes (`*Query.php`)
- **Purpose**: Retrieve read-only tabular or single-row data.
- **Dependency**: Injects `App\Foundation\Database`.
- **Return Type**: Associative `array` or `null`.
- **Invariants**: Must **never** execute `INSERT`, `UPDATE`, `DELETE`, or `ALTER`. Must use prepared statements with positional parameters (`?`).

### Command Classes (`*Command.php`)
- **Purpose**: Execute atomic state mutations.
- **Dependency**: Injects `App\Foundation\Transaction` or `App\Foundation\Database`.
- **Return Type**: Generated integer ID (`int`), affected row count (`int`), or `void`.
- **Invariants**: Must **never** perform presentation formatting. Any multi-table write must execute inside an explicit `$tx->begin() ... $tx->commit()` boundary.

---

## 5. Repository Directory Map

```
C:\Sistema_Ferreto\
├── .cursor/rules/             # Editor-specific AI rules (openspec, ui)
├── assets/                    # Asset provenance tracking (provenance.json)
├── config/                    # Application routing configuration (routes.php)
├── database/
│   ├── migrations/            # Deterministic, numbered SQL migrations (0001-0007)
│   └── seeds/                 # Minimal development probes (NO default user seeds)
├── docs/
│   ├── ai/                    # Specialized AI agent onboarding and architecture docs
│   └── ui/                    # Canonical UI/UX design specification (DESIGN.md)
├── openspec/
│   ├── changes/archive/       # Historical completed & archived SDD changes
│   └── specs/                 # Current canonical domain specifications
├── public/
│   ├── assets/                # Vendored Bulma, HTMX, and custom ferreto.css / app.js
│   └── index.php              # Central HTTP entrypoint
├── scripts/
│   └── console.php            # Custom CLI tool (migrate, seed, create-user, etc.)
├── src/
│   ├── Foundation/            # Framework infrastructure (Kernel, Router, DB, Csrf, Session)
│   └── Modules/
│       ├── Access/            # Authentication, RoleGuard, AuthSession, UserCommand/Query
│       └── Inventory/         # Products, Categories, Locations, Stock, Counts
├── templates/
│   ├── fragments/             # Reusable partial views (tables, count history)
│   ├── pages/                 # Full view templates (products, locations, counts, login)
│   └── layout.php             # Unified authenticated application shell
└── tests/
    ├── Integration/           # End-to-end and HTTP integration suites
    ├── Support/               # Test isolation traits and authentication helpers
    └── Unit/                  # Pure logic unit tests (RouteAccessPolicy, Csrf, etc.)
```
