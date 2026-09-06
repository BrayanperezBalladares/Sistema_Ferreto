# Sistema Ferreto foundation

This slice provides a reproducible PHP runtime and locally verified browser assets. It contains no business modules.

## Prerequisites

- PHP 8.5.10 with PDO, `pdo_mysql`, mbstring, OpenSSL, DOM, XML, XMLWriter, and ZIP
- Composer 2.10.3
- MariaDB client/server 10.4.19 or later; use an isolated database ending in `_test` for tests

For the Scoop PHP package used during verification, enable its supplied production configuration before running Composer:

```powershell
$env:PHPRC = "$(scoop prefix php)\php.ini-production"
php --ini
```

## Configure and bootstrap

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

`setup` installs the committed lock and fails if local asset bytes differ from `assets/provenance.json`. Migration and seed behavior arrives in PR 3 and currently fails closed. `serve` exposes static assets now and automatically uses `public/index.php` after PR 2 adds the HTTP boundary. Stop it with `Ctrl+C`.

## Verified inputs

| Input | Verified selection |
|---|---|
| Runtime | PHP 8.5.10; Composer 2.10.3 |
| Dev tools | PHPUnit 13.3.2; PHPStan 2.2.13; resolved transitive versions in `composer.lock` |
| Browser | htmx 2.0.10 (0BSD); Bulma 1.0.4 (MIT), copied from exact npm archives |

Checksums, archive sources, licenses, and the 2026-09-06 review date are recorded under `assets/`. No CDN or frontend build is used at runtime.
