# Claude Code Guidelines — Sistema Ferreto

> **Primary Authority**: Refer to [AGENTS.md](file:///C:/Sistema_Ferreto/AGENTS.md) for complete repository instructions and constraints.

## Quick Reference

- **Source of Truth**:
  1. Canonical Specs: `openspec/specs/`
  2. Source Code & Migrations: `src/`, `database/migrations/`
  3. UI Design Authority: `docs/ui/DESIGN.md`
  4. Current State: `docs/ai/CURRENT_STATE.md`
- **Commands**:
  - `composer test` — Run all unit and integration tests (in `*_test` database)
  - `composer analyse` — Run PHPStan at level `max`
  - `git diff --check` — Check whitespace and line endings
  - `openspec validate --specs` — Validate canonical specifications
- **Critical Invariants**:
  - PHP 8.5+ with `declare(strict_types=1);` on all PHP files.
  - Native PDO (no ORM), Command-Query Separation (`*Query` vs `*Command`).
  - Strict decimal precision: `DECIMAL(12,2)` money, `DECIMAL(12,3)` stock. Never use floats.
  - Route permissions are single-sourced in `App\Modules\Access\RouteAccessPolicy`.
  - Templates fail closed when `ViewPermissions` is absent.
  - Conventional Commits without `Co-Authored-By` or AI attribution.
  - Do not push or merge without explicit instruction.
