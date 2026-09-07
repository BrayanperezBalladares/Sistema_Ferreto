## Testing Capabilities

**Strict TDD Mode**: disabled
**Verified**: 2026-09-06

### Projects

| Relative path | Stack | Test command | Framework |
| ------------- | ----- | ------------ | --------- |
| . | PHP 8.5, MariaDB 11.4 | `composer test` | PHPUnit 13.3.2 |

### Test Layers

| Relative path | Layer       | Available | Tool |
| ------------- | ----------- | --------- | ---- |
| . | Unit | ✅ | PHPUnit (`tests/Unit/ContractsTest.php`) |
| . | Integration | ✅ | PHPUnit (`tests/Integration/{HttpTest,DatabaseTest,MigrationTest,SeedTest,ConsoleTest}.php`) |
| . | E2E | ✅ | Manual checklist (`tests/E2E/checklist.md`) |

### Coverage

| Relative path | Available | Command |
| ------------- | --------- | ------- |
| . | ❌ | — |

### Quality Tools

| Relative path | Tool         | Available | Command |
| ------------- | ------------ | --------- | ------- |
| . | Type checker | ✅ | `composer analyse` (PHPStan 2.2.13, Level max) |
| . | Lifecycle setup | ✅ | `composer setup` |
| . | Migrations | ✅ | `composer migrate` |
| . | Seeding | ✅ | `composer seed` |
| . | Development server | ✅ | `composer serve` |

### Resolution

Verified full test suite covering reproducible runtime, safe HTTP delivery, and MariaDB transactional persistence. All quality commands pass cleanly.
