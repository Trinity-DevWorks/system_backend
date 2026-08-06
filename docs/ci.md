# CI

GitHub Actions workflow: [`.github/workflows/ci.yml`](../.github/workflows/ci.yml)

| Step | Command / tool |
|------|----------------|
| Validate | `composer validate --strict` |
| Security | `composer audit` |
| Tests | `php artisan test` |
| Style | `./vendor/bin/pint --test` |
| Static analysis | `composer analyse` (PHPStan + Larastan) |

Local full gate: `composer ci:full`

PHPStan must stay clean at level 3 (`composer analyse`). Prefer fixing findings over adding ignore rules.
