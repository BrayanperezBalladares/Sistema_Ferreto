# Agent Guidelines — Sistema Ferreto

> **Primary AI Agent Instruction File**
> Target: Autonomous coding agents (Codex, Cursor, Claude Code, Gemini, Antigravity)
> Scope: Entire repository (`C:\Sistema_Ferreto`)

---

## 1. Project Identity

**Sistema Ferreto** is the core operational and administrative software system for **Ferreterías El Constructor**, an established retail and wholesale hardware enterprise. The project is an academic, production-grade business management system built with high architectural rigor, zero third-party framework dependencies, and deterministic validation.

---

## 2. Verified Technology Stack

| Layer | Component | Details / Constraints |
|---|---|---|
| **Runtime** | PHP 8.5.10 | Strict types (`declare(strict_types=1);`), PSR-4 autoloading via Composer |
| **Database** | MariaDB 10.4.19+ | InnoDB, `utf8mb4_unicode_ci`, native PDO (no ORM, no query builders) |
| **Architecture** | Modular Monolith | `src/Foundation`, `src/Modules/Access`, `src/Modules/Inventory` |
| **Delivery** | Server-Rendered HTML | Pure PHP templates, Bulma 1.0.4 CSS (vendored), custom `ferreto.css` |
| **Client Interactivity** | HTMX 2.0.10 | Vendored locally (0BSD), HTML-over-the-wire, minimal vanilla JS glue (`app.js`) |
| **Assets Pipeline** | Local & Zero-Build | No Node.js runtime, no npm build, no external CDNs; integrity tracked in `assets/provenance.json` |
| **Testing** | PHPUnit 13.3.2 | Isolated DB suites (`DatabaseIsolationTrait`), unit tests, HTTP integration tests |
| **Static Analysis** | PHPStan 2.2.13 | Configuration: `phpstan.neon`, Level: `max` (0 errors required) |
| **Specification / SDD** | OpenSpec 2.0 | Canonical specs under `openspec/specs/`, completed changes in `openspec/changes/archive/` |

---

## 3. Source-of-Truth Hierarchy

When evaluating requirements, constraints, or code changes, follow this strict precedence:

1. **Canonical OpenSpec Specifications** under `openspec/specs/` (Authoritative for functional requirements)
2. **Current Production Source Code & Database Migrations** (`src/`, `database/migrations/`, `public/index.php`)
3. **Automated Test Suites** under `tests/` (Authoritative for invariants and contracts)
4. **Project Documentation** under `docs/` (`docs/ui/DESIGN.md` governs presentation; `docs/ai/*` provides architecture guidance)
5. **Archived OpenSpec Changes** under `openspec/changes/archive/` (Historical rationale only; **NEVER** treat as active backlog)

> [!WARNING]
> If a conflict is discovered between code and specifications, **STOP and report the contradiction**. Never silently reinterpret or pick one over the other.

---

## 4. Core Architectural Rules

- **Modular Monolith**: Code is partitioned into `App\Foundation` (infrastructure) and domain modules (`App\Modules\Access`, `App\Modules\Inventory`). New domains (e.g. Sales, Purchasing) must be isolated modules under `src/Modules/<Domain>`.
- **Central HTTP Entrypoint**: All requests enter through `public/index.php` and are dispatched via `App\Foundation\Kernel` and `App\Foundation\Router`. Never bypass the Kernel or guards.
- **Native PDO (No ORM)**: Data access uses prepared SQL statements directly via `App\Foundation\Database` and `App\Foundation\Transaction`.
- **Command-Query Separation (CQS)**: Reads live in `*Query` classes returning associative arrays. Mutations live in `*Command` classes taking `Transaction` or `Database` and returning generated IDs or void.
- **Server-Side Rendering**: Templates live in `templates/pages/` and `templates/fragments/`, wrapped by `templates/layout.php`. Output escaping is mandatory via `App\Foundation\Renderer::escape($val)`.
- **ViewContext**: Shared layout and user data are encapsulated in `App\Foundation\ViewContext` and injected into `Renderer`.
- **HTMX Partial Updates**: Endpoints support full navigation and partial HTML fragments. In HTMX requests (`hx-request: true`), return only the target fragment.
- **Route Authorization Authority**: `App\Modules\Access\RouteAccessPolicy` is the single canonical authorization matrix for all routes and roles.
- **UI Permissions**: Templates evaluate capability through `App\Modules\Access\ViewPermissions` (which wraps `RouteAccessPolicy`). If `ViewPermissions` is missing, templates **fail closed** (`static fn(): bool => false`).
- **Physical Count Invariant**: Counts in `conteo_inventario` are purely observational. They **MUST NEVER** mutate `inventario_stock.cantidad`.
- **Decimal Precision**: Monetary values use `DECIMAL(12,2)`. Stock quantities use `DECIMAL(12,3)`. **NEVER** parse or calculate monetary values using floating-point types (`float`). Store and format as strings/exact decimals.

---

## 5. Security & Safety Rules

- **Zero Hardcoded Secrets**: Never commit passwords, API keys, or `.env` files. The app reads environment variables injected into the process; it does not read `.env` files.
- **No Migration Seeds for Users**: Migrations and seed files must **never** create default users or hardcoded credentials. Development accounts are provisioned exclusively via CLI.
- **Password Contract**: Minimum length = 15 Unicode characters; maximum UTF-8 encoded length = 72 bytes. Passwords $>72$ bytes are rejected with a validation error (never silently truncated).
- **Password Hashing**: Native bcrypt with explicit cost 10 (`PASSWORD_BCRYPT`, `['cost' => 10]`).
- **Timing Attack Mitigation**: When authenticating missing or empty usernames, verify against `Authenticator::DUMMY_HASH`. Existing non-active accounts verify against their stored hash.
- **Account Lockout**: 6th failed attempt within a fixed 10-minute window locks the account (`bloqueado`). Unlocking is strictly administrative via `scripts/console.php unlock-user`.
- **Session Authority**: Server-side sessions via `App\Foundation\NativeSession`. Revalidated against the database row on every protected request. Inactivity timeout: 20 minutes for `cajero`, 30 minutes for others.
- **Never Weaken Middleware**: Never disable `AuthGuard`, `RoleGuard`, or `Csrf` to make tests pass.
- **Sensitive Logging Prohibition**: Never log plaintext passwords, password hashes, session IDs, or CSRF tokens.

---

## 6. OpenSpec / SDD Workflow

Significant new capabilities or structural changes must follow the OpenSpec cycle:

1. **Explore**: Inspect current code, migrations, and canonical specs.
2. **Propose**: Create `openspec/changes/<change-name>/proposal.md` defining intent, why, scope, and impact.
3. **Spec**: Define delta requirements in `openspec/changes/<change-name>/specs/<domain>/spec.md` with `ADDED`, `MODIFIED`, `REMOVED` sections and Gherkin scenarios.
4. **Design**: Detail architectural decisions, schema migrations, and guard placements in `design.md`.
5. **Tasks**: Break implementation into bounded, reviewable tasks in `tasks.md`.
6. **Apply**: Implement code task by task with corresponding tests.
7. **Verify**: Run full regression (`composer test`, `composer analyse`, `openspec validate`).
8. **Archive**: Use `openspec archive <change-name> --yes` to reconcile delta specs into `openspec/specs/` and move the change to `openspec/changes/archive/`.

> [!NOTE]
> Documentation-only updates, bug fixes, or minor maintenance do not require a functional OpenSpec change.

---

## 7. Development & Git Workflow

- **Branching**: Branch from an up-to-date `main`. Use `feature/<name>` for features, `fix/<name>` for fixes, `docs/<name>` for documentation.
- **Bounded Scope**: Do not modify files unrelated to the assigned task.
- **Commit Messages**: Follow Conventional Commits (`feat(...)`, `fix(...)`, `test(...)`, `style(...)`, `chore(...)`, `docs(...)`).
- **Commit Hygiene**: **NEVER** add `Co-Authored-By` or AI attribution trailers.
- **No Premature Push/Merge**: Keep changes local. Do not push to remote or merge into `main` without explicit maintainer instruction.

---

## 8. Canonical Verification Commands

Every change must be validated using these repository commands:

```powershell
# 1. Run all unit and integration tests (isolated in *_test database)
composer test

# 2. Run static analysis at Level max
composer analyse

# 3. Check for whitespace or line-ending drift
git diff --check

# 4. Validate all canonical specifications
openspec validate --specs
```

If an active OpenSpec change is in progress, also validate it:
```powershell
openspec validate <change-name>
```

---

## 9. Essential Documentation Links

Before modifying any part of the system, consult the specialized guides under `docs/ai/`:

- [docs/ai/CURRENT_STATE.md](file:///C:/Sistema_Ferreto/docs/ai/CURRENT_STATE.md) — What is implemented vs pending today
- [docs/ai/ARCHITECTURE.md](file:///C:/Sistema_Ferreto/docs/ai/ARCHITECTURE.md) — Request pipeline, CQS pattern, handlers, and rendering
- [docs/ai/DATABASE.md](file:///C:/Sistema_Ferreto/docs/ai/DATABASE.md) — Real schema, relationships, migrations, and isolation
- [docs/ai/DEVELOPMENT_WORKFLOW.md](file:///C:/Sistema_Ferreto/docs/ai/DEVELOPMENT_WORKFLOW.md) — Step-by-step development and testing protocol
- [docs/ai/PROJECT_CONTEXT.md](file:///C:/Sistema_Ferreto/docs/ai/PROJECT_CONTEXT.md) — Business domain, actors, and enterprise goals
- [docs/ai/ROADMAP.md](file:///C:/Sistema_Ferreto/docs/ai/ROADMAP.md) — Future modules and architectural recommendations
- [docs/ui/DESIGN.md](file:///C:/Sistema_Ferreto/docs/ui/DESIGN.md) — Canonical design system, color tokens, and UI layout rules
