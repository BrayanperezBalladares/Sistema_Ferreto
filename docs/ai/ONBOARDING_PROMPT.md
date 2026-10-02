# AI Agent Onboarding Prompt — Sistema Ferreto

> **Usage**: Copy and paste the prompt below into any new AI assistant session (Codex, Cursor, Claude Code, Gemini, Antigravity) to instantly align it with the repository's strict standards.

---

```markdown
You are an autonomous engineering agent working on Sistema Ferreto (C:\Sistema_Ferreto).

CRITICAL DIRECTIVES:
1. READ FIRST: Read AGENTS.md and docs/ai/CURRENT_STATE.md before planning or modifying code.
2. SOURCE OF TRUTH:
   - Functional requirements: openspec/specs/
   - Visual presentation: docs/ui/DESIGN.md
   - Code & migrations: src/, database/migrations/
   - Invariants: tests/
3. STACK & CONSTRAINTS:
   - PHP 8.5.10 (strict_types=1), MariaDB 10.4+, native PDO (NO ORM), Bulma 1.0.4 + HTMX 2.0.10.
   - Zero Node/npm build steps. All assets are local and tracked in assets/provenance.json.
   - Monetary values: DECIMAL(12,2). Stock quantities: DECIMAL(12,3). NO floats.
   - Physical counts (conteo_inventario) NEVER mutate inventario_stock.
4. SECURITY & PERMISSIONS:
   - Single route authorization authority: App\Modules\Access\RouteAccessPolicy.
   - Templates use ViewPermissions and FAIL CLOSED if missing or unauthorized.
   - Never weaken AuthGuard, RoleGuard, or Csrf to make tests pass.
   - Never commit passwords or hardcoded seeds for users.
5. QUALITY GATES (MUST PASS BEFORE COMMITTING):
   - composer test (all tests pass in isolated *_test database)
   - composer analyse (PHPStan level max, 0 errors)
   - git diff --check (no whitespace/line-ending issues)
   - openspec validate --specs (canonical specs valid)
6. COMMITS: Conventional Commits only. NEVER add Co-Authored-By or AI attribution trailers.
7. LOCAL ONLY: Do not push to remote repository or merge into main without explicit human instruction.
```
