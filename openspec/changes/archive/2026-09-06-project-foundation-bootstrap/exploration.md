# Project Foundation Bootstrap Exploration

## Exploration: Project Foundation Bootstrap

### Current State
The workspace has requirements, OpenSpec artifacts, a professor presentation, and a PHP CRUD reference; it has no application project, PHP/Composer executable, dependency manifest, migrations, or test command. R1–R10 remain the functional baseline, but this change establishes infrastructure only.

The professor presentation fixes PHP, HTMX, Bulma, server-rendered full/partial HTML, HTML-over-the-wire, minimal custom JavaScript, and no frontend build. The reference POC demonstrates HTMX fragment swaps, server validation, PDO prepared statements, escaping, and Bulma presentation. It also uses per-action scripts, hard-coded credentials, exposed connection errors, CDN Bulma, and no CSRF, migrations, or tests; its README explicitly excludes it as production architecture.

### Affected Areas
- `public/` — future single HTTP entry point and locally served static assets.
- `src/Foundation/` — small cross-cutting kernel for routing, rendering, configuration, errors, logging, validation, CSRF, escaping, sessions, and PDO connection/transactions.
- `src/Modules/` — future module boundary only; no R1–R10 behavior or schema in this change.
- `templates/` — shared layout, full pages, and named HTMX fragments.
- `database/migrations/` and `database/seeds/` — ordered schema bootstrap and separately runnable development-only fixtures.
- `tests/` and project command scripts — foundation verification and reproducible run/test entry points.
- `config/`, `.env.example`, and asset provenance records — non-secret defaults, environment contract, and locally auditable asset source/version/checksum records.

### Approaches
1. **Small PHP modular monolith (recommended)** — one deployable server-rendered application, one OLTP database, central route table, thin Foundation kernel, and future module-owned handlers/templates/query-command classes.
   - Pros: Fits the fixed HTML-over-the-wire stack; preserves future inventory, sales, purchasing, and transfer transaction boundaries; minimizes operations and duplicated cross-cutting controls.
   - Cons: Requires discipline so Foundation does not become a generic framework.
   - Effort: Medium.

2. **Per-action PHP scripts following the reference POC** — each endpoint owns request handling, query, and HTML output.
   - Pros: Smallest initial file count; directly familiar from the POC.
   - Cons: Repeats routing, error, security, configuration, and rendering policies; the reference already demonstrates unsafe credential/error handling and lacks CSRF/migrations/tests.
   - Effort: Low initially, High to maintain.

3. **Framework or distributed services** — introduce a full PHP framework, microservices, or an API-first frontend.
   - Pros: Broad built-in conventions or independent deployment potential.
   - Cons: Exceeds locally evidenced needs and conflicts with the lightweight PHP/HTMX/Bulma direction; adds dependency, deployment, and consistency complexity before business boundaries are proven.
   - Effort: High.

### Recommendation
Adopt the small modular monolith. Use a single front controller and explicit route table; routes return complete HTML for normal navigation and focused named fragments for HTMX requests. Keep native PHP request/response adapters thin and use templates as the rendering boundary.

Use Composer as the dependency manifest/autoload/lock strategy, without naming or pinning package versions from this exploration. Prefer native PHP plus a deliberately small internal dispatcher unless Proposal/Design documents a concrete need for a router package. Use PHPUnit as the intended development test framework with a canonical `composer test` command; the first proof must exercise a non-business route through the front controller and verify full-page versus HTMX-fragment rendering. The exact package set, PHP compatibility target, and scripts are Proposal/Design decisions and must be locally installable before implementation.

Recommend **MariaDB as the single OLTP engine**: the professor POC uses a MySQL PDO DSN, the future R1/R2/R3/R5/R9 flows share relational transactional stock and location facts, and one engine is simpler for the team than dual-engine portability. This is not a current-version claim. Use PDO behind module-owned query/command classes and an explicit transaction helper; do not permit direct PDO in handlers, a generic repository layer, an ORM, or an ETL connection in the foundation.

Use ordered, versioned SQL migrations with a tracked migration state and separate idempotent development seeds. Proposal/Design must select the runner mechanism without fabricating package facts. Configuration comes from validated environment variables; commit `.env.example` only, keep secrets out of source, and document the local variable contract. The workspace currently has neither PHP nor Composer on PATH, so bootstrap instructions must state required tools without asserting versions.

For assets, copy reviewed HTMX and Bulma files into `public/assets/` with a provenance record containing the source location, observed version, checksum, and review date. The POC locally contains HTMX `2.0.10` and references Bulma `1.0.4` from a CDN; these are reference observations only, not foundation selections. No build step or runtime CDN dependency is required.

Foundation conventions should include contextual HTML escaping, server-authoritative validation, CSRF tokens for state-changing browser requests (including HTMX), a session-ready boundary without authentication policy, correlation-aware logging, and generic client errors that never disclose exceptions or secrets. Preserve a future OLTP-to-ETL/DW seam through stable identifiers, timestamps, migration history, and a later documented extraction interface; do not create ETL jobs, DW schema, analytics connections, or events now.

### Risks
- Exact runtime, database, package, and asset versions are not locally validated; Proposal/Design must not invent them.
- A health or infrastructure demonstration could become hidden product CRUD; it must contain no R1–R10 business behavior or schema.
- The POC is useful interaction evidence but cannot be copied as the security, routing, configuration, or production layout model.
- Native and containerized local setup could drift; implementation must provide one documented, repeatable bootstrap/run/migrate/seed/test path and verify it locally.

### Explicit Deferrals and Exclusions
- No catalog, inventory, sales, purchasing, transfers, accounts, audit, reporting, ETL/DW, regulatory, or other R1–R10 business module.
- No authentication/authorization policy, roles, password policy, or session lifecycle; only a session-ready seam.
- No DW/ETL/BI technology, extraction schedule, deployment platform, CI provider, backup frequency, RPO/RTO, or jurisdiction-specific R10 implementation.
- No POS offline mode, electronic invoicing, FIFO/lots, supplier-ranking algorithm, customer credit, GPS, B2B, 2FA, SPA/API-first frontend, frontend build step, microservices, CQRS/event bus, ORM, or generic repository framework.

### Pre-Proposal Handoff
- `research_selected: false`
- `evidence_scope: verified local artifacts only`
- `product_decisions: confirmed for the stated foundation scope and exclusions`
- `unresolved architecture choices: to be resolved by Proposal/Design as explicitly requested, not treated as product blockers` — exact runtime/package/asset versions, Composer script details, migration runner, test database mechanics, router package decision, and deployment/container choice.
- `proposal_ready: true` — local evidence is sufficient to propose the foundation without exact external version or security claims.
- `proposal_constraints: no fabricated current versions, no business modules, no modification of professor references or original requirements`

### Ready for Proposal
Yes — request user review and approval before launching Proposal. The proposal must preserve the above constraints and resolve implementation choices only to the degree needed for a reproducible foundation.
