# Proposal: Bootstrap the Project Foundation

## Intent

**ARCHITECTURE DECISION:** Build a PHP modular monolith with one OLTP database. **OFFICIAL FUNCTIONAL REQUIREMENT:** one boundary fits sales/stock (R2), purchasing/stock (R3), controls (R5), tracing (R6), and branch transfers (R9).

## Scope

**PROFESSOR TECHNICAL BASELINE:** PHP, HTMX, Bulma, full/partial server HTML, minimal custom JavaScript, no frontend build.

- **In:** structure/routing; locked dependencies/assets; MariaDB/PDO; migrations/seeds; config; tests/analysis; logging/errors; validation, CSRF, escaping, session seam; local commands; concept-free health flow.
- **Out:** R1–R10 modules; auth policy; DW/ETL/deployment; offline POS, e-invoicing, FIFO/lots, approvals, credit, ranking, GPS/B2B/2FA; frameworks, SPA/API-first UI, microservices, CQRS/events, Kubernetes, ORM/repositories, copied CRUD architecture.
- **Deferred:** verified versions, hosting, authentication policy, DW topology.

## Capabilities

### New Capabilities
- `reproducible-project-runtime`: bootstrap, config, commands, assets.
- `server-rendered-http-delivery`: routes, full/HTMX templates, validation, errors.
- `transactional-data-foundation`: PDO, schema lifecycle, security/extraction seams.

### Modified Capabilities
None; `openspec/specs/` is empty.

## Approach

**RECOMMENDATION:** Use `public/{index.php,assets/}`, `src/Foundation/`, empty `src/Modules/`, `templates/`, `config/`, `database/{migrations,seeds}/`, `tests/`, and scripts. An explicit route table dispatches thin handlers to layouts/fragments. Module queries/commands use prepared PDO and explicit transactions. Ordered SQL migrations track checksums; development seeds are separate/idempotent. Stable IDs, UTC timestamps, and history preserve a future read-only OLTP extraction contract without ETL code.

## Dependencies

Implementation MUST verify compatibility, pin Composer resolutions and local asset provenance/checksums, and review advisories.

| Choice/status | Need, maintenance/security, smaller alternative |
|---|---|
| Composer/tool | Autoload/scripts/lock; manual includes are smaller but unauditable. |
| Native router/runtime | Few routes favor tested code; FastRoute if matching grows. |
| Native SQL runner/tool | One-engine SQL is inspectable; lock/checksum it; Phinx is richer/heavier. |
| PHPUnit, PHPStan/dev | Native assertions/lint lack runner/type analysis; lock/update; scripts are weaker. |
| phpdotenv/runtime | Validates local `.env`; exclude secrets/update; injected environment is smaller. |
| Monolog/runtime | Levels/context exceed `error_log`; redact/update; native logging is smaller. |
| MariaDB + PDO/runtime | Transactions fit coupling; prepared PDO avoids ORM; raw drivers are smaller. |

## Workflow and Safety

**DERIVED ENGINEERING REQUIREMENT:** `.env.example` excludes secrets. Composer commands cover setup, serve, migrate, seed, test, analyse. Context escaping, server validation, HTMX-aware CSRF, session adapters, correlation logs, and generic responses hide secrets/traces.

## Affected Areas

New paths listed above; `reference/`, requirements, and old `project-foundation` remain unchanged.

## Alternatives, Risks, Rollback

Per-action scripts duplicate controls; enterprise/distributed designs add unjustified layers. Risks: drift, scope creep, product-like demo. Mitigate with locks, narrow contracts, concept-free tests. Roll back files/database objects via down migrations; preserve references/requirements.

## Success Criteria

- [ ] Fresh setup installs, configures, migrates, seeds, serves, tests, analyses.
- [ ] Tests prove route→handler→full/partial HTML→HTMX→database and validation/error/CSRF safety.
- [ ] No business schema, secret, CDN runtime, frontend build, or exposed exception exists.
