# Project Foundation Exploration

## Decision snapshot

Build a small **PHP modular monolith** with server-rendered pages and HTMX fragments, one MariaDB OLTP database, Composer-managed PHP tooling, PDO as the database boundary, versioned SQL migrations, and PHPUnit-based tests. This is a proposed engineering foundation only; it implements no business module or R1–R10 workflow.

The professor POC proves the PHP → HTML fragment → HTMX update → Bulma path and provides useful interaction conventions. It is not a production architecture: it contains hard-coded database credentials, per-action PHP endpoints, no migrations, no CSRF protection, no tests, and a mixed CDN/local asset policy.

## Evidence inventory and authority

| Evidence | Finding | Authority and use |
|---|---|---|
| Official statement reconciliation | R1–R10 define the canonical functional scope; it includes transactional inventory, sales, purchasing, transfers, audit, reporting, ETL/DW, and regulated documentation. | **OFFICIAL FUNCTIONAL REQUIREMENT**; foundation must not expand or implement it. |
| Reconciliation baseline | Identity for sensitive actions, branch context, supplier terms/receiving, transfer actor/confirmation, and ETL traceability are derived later needs. Offline POS, e-invoicing before jurisdiction, FIFO/lots, fixed RBAC, and detailed backup targets are excluded or optional. | **DERIVED ENGINEERING REQUIREMENT** only where a shared technical seam is needed; not foundation features. |
| Professor baseline | PHP, HTMX, Bulma, server-rendered HTML/full pages and fragments, HTML-over-the-wire, minimal JavaScript, and no frontend build are fixed. | **PROFESSOR TECHNICAL BASELINE**. |
| Local professor POC | HTMX 2.0.10 is vendored locally; Bulma 1.0.4 is CDN-linked. The POC uses PDO prepared statements, HTML escaping, server validation, status-specific fragments, `HX-Trigger`, and `HX-Redirect`-style concepts. | Reference evidence only, not a production mandate. |
| Local professor POC gaps | `conexion.php` embeds credentials and exposes connection errors; action scripts own routing/data/rendering; destructive delete lacks CSRF; no dependency manifest, migration, test, or environment separation exists. | Evidence that the POC must not be copied as production structure. |
| Existing OpenSpec config/testing capability | Workspace contains documents only, has no executable project, test runner, or canonical command; strict TDD is false. | **DERIVED ENGINEERING REQUIREMENT**: foundation must make an executable project and test command. |
| Detailed module documents | They describe future domains and show tight shared coupling around stock, branch/warehouse context, actors/audit, supplier orders, transfers, and analytical extraction. Their fixed roles, five-file layout, dual-engine portability, offline mode, fiscal specifics, and data model are non-authoritative. | Supporting, non-authoritative material. |

## R1–R10 coupling and modular-monolith fit

R1/R2/R3/R5/R9 share the same OLTP stock and branch/warehouse facts; R2/R6 share actor attribution; R3 and R9 alter inventory; R4/R7 consume operational facts; R8 observes all write paths; R10 attaches to sales/distribution later. These are transactionally coupled domains, not independently deployable services.

| Option | Trade-offs | Assessment |
|---|---|---|
| **Modular monolith (recommended)** | One deployable PHP application and OLTP database; modules own use cases, templates, and SQL migrations while sharing explicit kernel services. Preserves transaction boundaries for stock/sales/receiving/transfers. | Lowest operational cost and strongest integrity path for a greenfield team. |
| Unstructured scripts per action | Fast first screen, but routes, validation, error handling, configuration, and security drift across files; the POC illustrates this risk. | Reject. It cannot scale across R1–R10 safely. |
| Full-stack Laravel/Symfony | Mature capabilities, but framework conventions and dependency surface exceed the fixed lightweight baseline without evidence that their breadth is needed. | Reject for foundation; reconsider only if future requirements demonstrate a specific need. |
| Microservices/CQRS/event bus | Adds distributed consistency, event contracts, deployment, observability, and eventual-consistency hazards to stock correctness. | Reject. No independent scaling/team/deployment boundary justifies it. |

## Proposed minimal production boundaries

```text
public/                 HTTP entry point and immutable public assets
src/
  Foundation/           routing, request/response, templates, config, errors, logging, DB
  Modules/
    Health/             technical proof only (no business behavior)
    Accounts/           future identity/session/access boundary
    Locations/          future branches and warehouses
    Catalog/             future products and categories
    Inventory/           future stock and controls
    Sales/               future sales and recommendations
    Purchasing/          future suppliers and orders
    Transfers/           future inter-branch transfers
    Audit/               future audit and maintenance
    Reporting/           future operational reports
    AnalyticsIntegration/ future OLTP extraction contract
templates/
  layouts/ pages/ fragments/ shared/
database/
  migrations/ seeds/
tests/
  Unit/ Integration/ Support/
bin/                    migration, seed, and test entry commands
config/                 non-secret defaults and environment mapping
```

Each future module should expose route registration, application handlers, module templates/fragments, and migrations. `Foundation` may provide only cross-cutting mechanisms: request classification, response rendering, database connection/transactions, validation result rendering, CSRF, logging, and error translation. It must not become a generic repository/service/DTO framework.

| Item | Classification | Recommendation |
|---|---|---|
| R1–R10 business modules | **OFFICIAL FUNCTIONAL REQUIREMENT** | Reserve module boundaries; do not implement business behavior now. |
| Server-rendered full and partial HTML | **PROFESSOR TECHNICAL BASELINE** | Make templates first-class and return HTML rather than a primary JSON API. |
| Explicit module seams around stock, actor, branch, and reporting facts | **DERIVED ENGINEERING REQUIREMENT** | Keep these seams visible because later official flows cross them. |
| Modular monolith and small Foundation kernel | **ARCHITECTURE DECISION** | Use one deployable application and one OLTP database. |
| Avoid the historic five-file-per-module mandate | **RECOMMENDATION** | Apply responsibilities, not filename ceremony; create only the files a module needs. |

## Dependency decision matrix

Exact package versions and supported PHP version require the bounded research lanes below before Proposal. Lock exact versions in `composer.lock`; do not use floating production dependencies.

| Need | Candidate and classification | Problem solved / why native code is insufficient | Runtime or dev | Smaller alternative and decision |
|---|---|---|---|---|
| PHP dependency management | Composer — **ARCHITECTURE DECISION** | Resolves/autoloads audited PHP libraries reproducibly; hand-managed includes and ZIPs do not provide transitive version/security tracking. | Runtime manifest/lock; Composer executable is development/CI tooling. | No Composer is viable only while zero PHP packages exist, but it weakens reproducibility. **RECOMMENDATION:** adopt Composer with a minimal manifest and committed lockfile. |
| Routing | A small mature PSR-7-compatible router, subject to research — **RECOMMENDATION** | Centralizes method/path matching, parameters, 404/405 behavior, and route naming; a bespoke router risks reimplementing security/error edge cases. | Runtime. | A deliberately tiny project dispatcher is acceptable if it remains under a documented fixed route table and no router package meets compatibility needs. Do not add a framework. |
| HTTP request/response | Native PHP superglobals plus thin Foundation adapters — **ARCHITECTURE DECISION** | Keeps the baseline small while isolating raw globals from application handlers. | Runtime. | Full PSR-7 stack is unnecessary unless the chosen router requires it. |
| Migrations | Phinx or another mature PHP migration tool, subject to research — **RECOMMENDATION** | Tracks ordered schema evolution, migration state, and repeatable CLI execution; ad hoc SQL scripts cannot reliably establish applied state. | Development/operations dependency. | A numbered SQL runner is smaller but must safely track checksums, ordering, failure, and transactions; reject unless it demonstrably meets these needs. |
| Test framework | PHPUnit — **RECOMMENDATION** | Provides assertions, isolation, discovery, exit status, and CI-ready reporting; custom test scripts create a maintenance burden immediately. | Development-only. | Pest adds syntax preference but also another layer; choose PHPUnit for the smallest mature baseline. |
| Static analysis | PHPStan at a conservative initial level — **RECOMMENDATION** | Finds type/undefined-path defects native PHP does not report before runtime. | Development-only. | Native `php -l` catches syntax only; use it in addition, not instead. |
| Environment loading | Native environment variables plus a small dotenv loader only for local development — **RECOMMENDATION** | Keeps secrets out of source and local bootstrap repeatable; PHP alone does not load a `.env` file. | dotenv is development-only when deployment injects environment variables. | Require developers to export every variable manually: smaller, but error-prone. |
| Logging | PSR-3-compatible logger, preferably Monolog only if structured file/stderr logging cannot stay small — **RECOMMENDATION** | Standardizes levels/context/rotation destination; `error_log` alone is a viable initial adapter. | Runtime. | Begin with a Foundation `Logger` backed by `error_log`; add Monolog only after research establishes a concrete sink/rotation requirement. |
| HTMX/Bulma assets | Checked-in, versioned, integrity-verified static assets — **ARCHITECTURE DECISION** | Avoids CDN availability/privacy/version drift and supports reproducible local runs without a build. | Runtime static assets. | Pinned CDN URLs plus SRI are smaller, but depend on external availability. **RECOMMENDATION:** vendor the reviewed releases, record upstream source/version/checksum, and update deliberately. |

The POC's locally vendored HTMX identifies version `2.0.10`; its Bulma CDN URL pins `1.0.4`. These are evidence, not approval to inherit them. Foundation must verify support/security status before pinning its own versions.

## Persistence and data-access evaluation

| OLTP option | Integrity/stock correctness | PHP/migrations/local setup | ETL and reporting boundary | Team complexity | Outcome |
|---|---|---|---|---|---|
| MariaDB (recommended) | Relational constraints, foreign keys, transactions, and row-level concurrency fit future stock writes. | Strong PDO support, POC already uses MySQL DSN, simple container/local installation, broad migration support. | One authoritative OLTP source; future ETL reads from a documented extraction boundary into a separate DW choice. | Low-to-medium. | **ARCHITECTURE DECISION:** choose one MariaDB version after research. |
| PostgreSQL | Equally strong relational/integrity capabilities and PHP support. | Excellent tooling, but no local project evidence and a different operational baseline. | Good ETL boundary. | Medium due to team/POC divergence. | Viable alternative if team hosting expertise favors it. |
| SQL Server | Strong integrity and enterprise operations. | PDO driver and local developer setup are more platform/licensing-sensitive; POC does not exercise it. | Good ETL ecosystem. | Medium-to-high unless organization already operates it. | Defer unless an organizational constraint selects it. |
| SQLite | Excellent for isolated tests and small demos. | Trivial local setup, but differs in concurrency/locking and operational behavior from multi-branch OLTP. | Not a production OLTP choice here. | Low initially, high later migration risk. | Use only for narrow unit-test fixtures if needed; do not choose as production engine. |

**RECOMMENDATION:** Select one production engine, not MariaDB/SQL Server portability. Dual portability multiplies SQL, migration, test, and concurrency validation without an official requirement. The foundation should define a single `Database` connection factory and transaction helper; it must not design the business schema.

| Data-access option | Benefits | Costs | Outcome |
|---|---|---|---|
| Direct PDO in handlers | Fewest lines. | Couples HTTP/template/domain flow to SQL and repeats transactions/error mapping. | Reject outside tiny technical health proof. |
| PDO behind module query/command classes (recommended) | Prepared statements, explicit transaction scopes, testable queries, no hidden SQL dialect. | Small amount of structure. | **ARCHITECTURE DECISION:** use PDO with module-owned query/command classes; no generic repository abstraction. |
| Query builder | Safer composition for dynamic filters. | New abstraction/dialect behavior with limited benefit for known relational queries. | Defer; add only for demonstrated complexity. |
| ORM/active record | Rapid CRUD scaffolding. | Hidden queries/transactions and domain-to-schema coupling are poor fits for stock-critical flows. | Reject for foundation. |

## Migrations, seed, configuration, and secrets

| Concern | Options | Recommendation |
|---|---|---|
| Migrations | Versioned SQL files with a runner; mature migration tool. | **RECOMMENDATION:** choose a mature migration tool after its official documentation is researched; require ordered, repeatable `migrate` and `status` commands. Migrations create structure only, not product data. |
| Seeds | No seed data; idempotent development fixtures; production bootstrap data. | **RECOMMENDATION:** separate idempotent development fixtures from migrations. No business seed catalog belongs in foundation. |
| Environment config | PHP constants; `.env` committed; `.env.example` plus runtime variables. | **ARCHITECTURE DECISION:** use validated environment variables, commit `.env.example` only, and inject real secrets outside version control. |
| Secrets | Hard-coded credentials (POC); local `.env`; secret manager. | **RECOMMENDATION:** reject hard-coded values. Use local ignored `.env` for development and platform-provided variables/secret storage later; deployment provider is deferred. |
| Dependency integrity | Floating versions/CDN; locked packages and checksummed static assets. | **RECOMMENDATION:** committed lockfile, exact asset versions, documented source/checksum, and planned dependency update review. |

## HTTP, templates, and HTMX conventions

| Scenario | Convention |
|---|---|
| Direct navigation | `GET` routes return a complete HTML document with shared layout, page template, and progressive HTML links/forms. |
| HTMX request | The same use case detects the HTMX request and returns the smallest named fragment; it does not duplicate business logic or create a separate JSON API. |
| Route organization | Central route table maps method/path/name to module handlers, e.g. `/health`, `/products`, `/products/search`; never `module_c.php?action=x` as the production convention. |
| Templates | Layouts, pages, reusable components, and fragments are distinct. Fragments must be valid meaningful HTML and escape all untrusted output. |
| Forms | HTML constraints aid UX; server-side request validation is authoritative. Invalid HTMX submissions return a `422` form/field-error fragment; full-page submissions redisplay the page with errors. |
| Success after write | Prefer Post/Redirect/Get for non-HTMX forms. For HTMX, return the replacement fragment plus explicit `HX-Trigger` notification/list-refresh headers, or `HX-Redirect` when the correct outcome is navigation. |
| Notifications | Render accessible Bulma notification fragments into a stable live region. Avoid bespoke JavaScript for normal success/error states. |
| Error responses | Known validation/conflict/not-found errors map to safe HTML fragments/pages. Unexpected errors log correlation data and return a generic 500 page/fragment without exception details. |
| Progressive enhancement | Core navigation and form submission work as ordinary HTTP. HTMX enhances partial updates; minimal JavaScript is limited to capabilities HTMX/HTML cannot provide, such as an accessible custom confirmation dialog. |

These conventions preserve the professor baseline while strengthening the POC's useful `422`/`409`, validation, escaping, and HTMX event practices.

## Security, validation, logging, and errors

| Concern | Classification | Foundation position |
|---|---|---|
| Sensitive access/actor attribution | **OFFICIAL FUNCTIONAL REQUIREMENT** (R6) | Future modules must support it; no account/authorization feature is implemented now. |
| Parameterized database access and output escaping | **DERIVED ENGINEERING REQUIREMENT** | Require PDO prepared statements and contextual HTML escaping at every output boundary. |
| CSRF protection for state-changing browser requests | **DERIVED ENGINEERING REQUIREMENT** | Establish token issuance/verification middleware and HTMX header/form conventions, even before protected accounts exist. |
| Session-ready request boundary | **DERIVED ENGINEERING REQUIREMENT** | Provide a narrow session abstraction/configuration seam; do not select login, JWT, roles, or account policy. |
| Safe errors and structured logging | **DERIVED ENGINEERING REQUIREMENT** | Define correlation ID, severity, request metadata, and safe client error renderer; never display database credentials or exception messages as the POC currently can. |
| Validation result model | **ARCHITECTURE DECISION** | Use a small typed/structured validation result consumed by page and fragment templates; do not introduce a validation framework unless research demonstrates value. |
| Security headers, cookie flags, trusted proxy policy | **RECOMMENDATION** | Establish documented defaults and test them when a web server/runtime target is selected; exact deployment headers remain deferred. |

## Testing and reproducible local workflow

| Capability | Foundation requirement |
|---|---|
| Canonical command | **ARCHITECTURE DECISION:** one documented command, proposed `composer test`, runs PHPUnit and exits non-zero on failure. Proposal must confirm exact scripts after package/version research. |
| Infrastructure proof | **DERIVED ENGINEERING REQUIREMENT:** add at least one real test proving the technical path (routing → handler → full/fragment HTML; and database connectivity/migration only when a test database is available). It must not introduce business behavior. |
| Test layers | Unit tests for Foundation pure logic; integration tests against a disposable MariaDB database for migrations/PDO; later browser/HTMX flow tests only where critical behavior warrants them. |
| Quality command | Proposed `composer check` runs PHP syntax, PHPUnit, and PHPStan. It is a quality gate, not a replacement for test design. |
| Local bootstrap | Pin a PHP version in Composer and document extensions; provide `.env.example`, a containerized MariaDB option, `composer install`, migrate/seed commands, a PHP development-server command, and the test command. |
| Reproducibility | Commit manifests, lockfile, migration history, asset provenance, and bootstrap documentation. Do not require Docker if native PHP/MariaDB setup is supported, but offer one canonical container path to reduce drift. |

The exact PHP runtime minor version, MariaDB version, container choice, migration package, and test database setup are **unresolved architecture choices** pending authoritative research. The foundation change should not turn strict TDD on until the executable project, command, and infrastructure test exist; its completion should update OpenSpec testing capabilities so subsequent changes can enable it.

## OLTP to future ETL/DW boundary

R7 requires ETL to a DW and R4 requires reporting/BI, but neither selects a technology, topology, dimensional model, cadence, or BI product. Foundation should preserve—not prematurely implement—the boundary:

- **OFFICIAL FUNCTIONAL REQUIREMENT:** future inventory, sales, and order data must be ETL-capable for the DW.
- **DERIVED ENGINEERING REQUIREMENT:** OLTP writes need stable identifiers, timestamps, and migration history so a future extraction contract can be defined and traced.
- **ARCHITECTURE DECISION:** the foundation owns one OLTP database connection/configuration only; it exposes no event bus, DW connection, or ETL job yet.
- **RECOMMENDATION:** later ETL reads through versioned, documented OLTP extraction queries/views or a dedicated extraction module, using a separate least-privilege database identity. This avoids analytics queries being embedded in transactional HTTP handlers.

## Explicitly deferred and excluded

| Deferred | Reason |
|---|---|
| Authentication, authorization model, roles, password/session policy | R6 requires the outcome, but no current foundation flow requires implementation. |
| DW/ETL tooling, BI platform, cadence, and deployment topology | R4/R7 require capability later; technologies are not prescribed and OLTP contracts are not yet stable. |
| Deployment/hosting, CI provider, backup frequency/RPO/RTO | No environment/service targets are authoritative. |
| Business schemas and workflows for all modules | This change is engineering infrastructure only. |
| POS offline, electronic invoicing, FIFO/lots/perishables, complex approvals, customer credit, supplier ranking algorithm, GPS/B2B/2FA | Excluded by the canonical baseline or explicitly out of scope. |
| React/Vue, frontend build pipeline, Kubernetes, microservices, CQRS, event bus, API-first UI, full-stack framework, generic repository abstraction, excessive layering, custom framework | Rejected absent compelling evidence. |

## Risks and unresolved choices

1. PHP/MariaDB/package versions cannot be pinned responsibly from local evidence alone; official compatibility and security documentation must be reviewed.
2. The chosen router and migration tool can accidentally pull a framework-sized dependency graph; evaluate package maintenance, PHP support window, security policy, and transitive dependencies before Proposal.
3. The foundation health demonstration can become a stealth business CRUD. Limit it to technical readiness and avoid product/catalog terminology or data model.
4. Containerized local development improves consistency but may create a Windows onboarding burden; native and container paths need one source of truth and tested instructions.
5. The POC shows useful interaction patterns but also unsafe credentials/error disclosure and route sprawl; copying it would carry those defects into production.
6. No jurisdiction is known for R10, and supplier-selection precedence remains unresolved; neither should block infrastructure but both block their respective business changes.

## Acceptance-shaping evidence for a later Proposal

A foundation proposal can be evaluated without business functionality if it requires:

- a PHP executable project with a central HTTP entry point and named routes;
- one complete-page and one HTMX-fragment response using Bulma and versioned local assets;
- one MariaDB target, PDO configuration from environment variables, prepared-access boundary, and no committed secrets;
- repeatable migrate/status and development-fixture commands, with no business schema;
- canonical `composer test` and at least one real infrastructure test;
- safe validation/error fragment behavior, CSRF convention, escaping helper, and structured logging/error boundary;
- documented local bootstrap/run/test instructions;
- no code for R1–R10 business modules, authentication, ETL/DW, deployment, or excluded scope.

## Bounded external research lanes before Proposal

| Lane | Authoritative sources to consult | Decision it unlocks |
|---|---|---|
| PHP and Composer support | PHP supported-version policy and Composer official documentation | Supported PHP minor, `composer.json` platform policy, lock/install workflow. |
| Router | Official documentation/release/security metadata for one small PHP router candidate and its PHP compatibility | Whether a mature router is materially safer than a fixed internal dispatcher. |
| Migrations | Official documentation for the shortlisted migration tool and MariaDB transaction/DDL behavior | Migration package, rollback policy, test-database workflow. |
| MariaDB/PDO | MariaDB official documentation plus PHP PDO MySQL documentation | Exact engine/image version, PDO DSN/options, transaction and local setup convention. |
| PHPUnit/PHPStan | Official PHPUnit and PHPStan compatibility documentation | Exact dev dependencies and canonical test/check scripts. |
| HTMX/Bulma distribution | Official HTMX and Bulma release/distribution/security guidance | Confirm supported asset releases, vendoring/SRI/provenance policy, and response header conventions. |
| dotenv/logger | Official docs for shortlisted dotenv and logging libraries | Determine whether native variables/`error_log` suffice or one small package is justified. |

## Readiness

**Ready for Proposal: No — user review and bounded external research are required.** The evidence supports the direction and rejects over-engineering, but the Proposal must not claim exact runtime/package/engine versions until the selected research lanes produce authoritative, current compatibility evidence. In interactive mode, the next action is for the user to review this recommendation and choose whether to commission the bounded research; no proposal should start automatically.
