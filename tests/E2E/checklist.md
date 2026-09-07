# Foundation E2E & Runtime Verification Checklist

This checklist documents the verified end-to-end behavior of the foundation bootstrap. No R1–R10 business flows are implemented.

## 1. Reproducible Runtime & Setup

- [x] **Locked Asset Verification**: `composer setup` executes `@php scripts/setup.php`, validating local assets against `assets/provenance.json` SHA256 checksums (`public/assets/htmx.min.js`, `public/assets/bulma.min.css`, `public/assets/app.js`). Fails closed if any asset is altered or missing.
- [x] **Configuration Validation**: Startup fails closed without printing secret values if any required environment variable is missing or if `TEST_DB_NAME` does not end in `_test`.

## 2. Server-Rendered HTTP Delivery

- [x] **Full Page Rendering**: `GET /health` returns HTTP 200 with complete HTML5 layout, Bulma styling, HTMX script tag, and form with CSRF token.
- [x] **HTMX Fragment Swap**: `GET /health` with `HX-Request: true` returns HTTP 200 with fragment HTML only (no outer `<html>` or `<head>`).
- [x] **Safe Form Handling**:
  - `POST /health` with invalid input returns HTTP 422 with validation error fragment.
  - `POST /health` with missing/invalid CSRF token returns HTTP 403 Forbidden.
  - `POST /health` with valid input performs safe redirect / in-place trigger.
- [x] **Path Traversal & Static Allowlist**:
  - Encoded separators (`..%2f`, `%2e%2e/`) return HTTP 400.
  - Non-allowlisted paths (`requirements.txt`, `CMakeLists.txt`, `README.sh`) return HTTP 404 with zero execution.
- [x] **Correlated Failure Boundary**: Unexpected application exceptions return generic HTTP 500 referencing an opaque correlation ID. No stack traces, paths, or secrets are exposed.

## 3. Transactional Data Foundation

- [x] **Ordered Migrations**: `php scripts/console.php migrate` bootstraps `schema_migrations`, checks applied checksums, acquires advisory lock, and applies pending migrations sequentially.
- [x] **Compensating Reversal**: `.down.sql` drops table and removes `schema_migrations` record upon successful execution.
- [x] **Idempotent Seeding**: `php scripts/console.php seed` inserts/updates fixed key (`id = 1`) on `infrastructure_probe`. Repeated execution produces no duplicate rows and no errors.
- [x] **Production Guard**: `SeedRunner` immediately rejects execution under `APP_ENV=production`.
- [x] **Scope Boundary**: Verified that only `schema_migrations` and `infrastructure_probe` tables exist. No business domain tables, auth, ETL, or DW pipelines are present.
