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

PHPStan ignores currently known issues via `phpstan-baseline.neon`. Do not grow the baseline casually — prefer fixing new findings.
