# Sistema Ferreto

Operational management software for **Ferreterías El Constructor**, an enterprise retail and wholesale hardware commercial business. Built as a high-discipline modular monolith using native PHP 8.5, MariaDB 10.4+, Bulma 1.0.4, and HTMX 2.0.10 with zero frontend build dependencies.

---

## Prerequisites

- PHP 8.5.10 with PDO, `pdo_mysql`, mbstring, OpenSSL, DOM, XML, XMLWriter, and ZIP
- Composer 2.10.3
- MariaDB client/server 10.4.19 or later; use an isolated database ending in `_test` for tests

For the Scoop PHP package used during verification, enable its supplied production configuration before running Composer:

```powershell
$env:PHPRC = "$(scoop prefix php)\php.ini-production"
php --ini
```

---

## Configure and Bootstrap

Use `.env.example` as the field inventory. The application reads injected variables only; it never loads `.env` files. Replace every `<set-locally>` value, then inject values in PowerShell, for example `$env:APP_ENV = 'development'`. Validation reports field names, never values.

Run the canonical workflow from the repository root:

```powershell
composer setup
composer migrate
composer seed
composer test
composer analyse
composer serve
```

- `setup` installs the committed lock and verifies that local asset bytes match `assets/provenance.json`.
- `migrate` executes deterministic database migrations under `database/migrations/`.
- `seed` populates environment probes.
- `test` runs the full automated test suite using the isolated test database.
- `analyse` runs PHPStan static analysis at level `max`.
- `serve` launches the PHP built-in web server at `http://127.0.0.1:8000` via `public/index.php`. Stop it with `Ctrl+C`.

---

## User Provisioning & Administration

To maintain security, no default user accounts or passwords are seeded in migrations or seed scripts. Create development or administrative accounts via the console CLI:

```powershell
# Create a new user account (prompts interactively for password conforming to policy)
php scripts/console.php create-user <username> <rol>

# Valid roles: administrador, bodeguero, cajero, compras

# Unlock a locked account after failed login attempts
php scripts/console.php unlock-user <username>
```

---

## AI-Assisted Development

Sistema Ferreto is designed to be safely and deterministically extended by autonomous AI coding assistants (Codex, Cursor, Claude Code, Gemini / Antigravity).

### Agent Entrypoints & Guides
- **Primary AI Guidelines**: [AGENTS.md](AGENTS.md) — Authoritative repository-wide instructions, constraints, and source-of-truth hierarchy.
- **Claude Code**: [CLAUDE.md](CLAUDE.md) — Thin adapter for Claude Code CLI sessions.
- **Gemini / Antigravity**: [GEMINI.md](GEMINI.md) — Thin adapter for Gemini and Antigravity environments.
- **Cursor Rules**: [.cursor/rules/openspec.mdc](.cursor/rules/openspec.mdc) and [.cursor/rules/ui.mdc](.cursor/rules/ui.mdc) — Editor rules for OpenSpec and UI styling.
- **Onboarding Prompt**: [docs/ai/ONBOARDING_PROMPT.md](docs/ai/ONBOARDING_PROMPT.md) — Copy-paste prompt for initializing new agent sessions.

### Specialized Architectural Documentation
- [docs/ai/CURRENT_STATE.md](docs/ai/CURRENT_STATE.md) — Exhaustive audit of implemented vs non-implemented features.
- [docs/ai/ARCHITECTURE.md](docs/ai/ARCHITECTURE.md) — Request pipeline, CQS pattern, rendering, and authorization.
- [docs/ai/DATABASE.md](docs/ai/DATABASE.md) — Schema migrations, relationships, decimal precision, and test isolation.
- [docs/ai/DEVELOPMENT_WORKFLOW.md](docs/ai/DEVELOPMENT_WORKFLOW.md) — Step-by-step OpenSpec + TDD feature development protocol.
- [docs/ai/PROJECT_CONTEXT.md](docs/ai/PROJECT_CONTEXT.md) — Business domain, enterprise actors, and operational boundaries.
- [docs/ai/ROADMAP.md](docs/ai/ROADMAP.md) — Future milestone trajectory.
- [docs/ui/DESIGN.md](docs/ui/DESIGN.md) — Canonical Industrial Precision Workspace design system.

---

## Verified Inputs

| Input | Verified selection |
|---|---|
| Runtime | PHP 8.5.10; Composer 2.10.3 |
| Dev tools | PHPUnit 13.3.2; PHPStan 2.2.13; resolved transitive versions in `composer.lock` |
| Browser | htmx 2.0.10 (0BSD); Bulma 1.0.4 (MIT), copied from exact npm archives |

Checksums, archive sources, licenses, and review dates are recorded under `assets/`. No CDN or frontend build is used at runtime.
