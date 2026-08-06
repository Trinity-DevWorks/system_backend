# Deployment readiness (Option B)

Operator notes for running and later deploying the ERP API.  
Related issue: [Trinity-DevWorks/Issues#29](https://github.com/Trinity-DevWorks/Issues/issues/29).

This is **scaffolding only** — there is no live staging/production host wired yet.

## Repositories

| Repo | Role |
|------|------|
| `system_backend` | Laravel multi-tenant API (this repo) |
| `system_frontend` | Next.js UI (sibling clone) |
| `system_blockchain` | Out of scope until it has code |

Expected local layout when using the full Compose profile:

```text
Projects/ERP/
  system_backend/    ← docker compose lives here
  system_frontend/   ← built via profile `with-frontend`
```

## Docker Compose (local production-shaped stack)

Uses **PostgreSQL** (same driver as local `DB_CONNECTION=pgsql`), Redis, API, queue, optional frontend.

From `system_backend`:

```bash
# API + PostgreSQL + Redis + queue worker
docker compose up -d --build

# Also build/run the Next.js frontend (requires sibling system_frontend)
docker compose --profile with-frontend up -d --build
```

If you previously ran the MySQL-based Compose stack, remove the old services/volumes once:

```bash
docker compose down
docker volume rm system_backend_mysql_data 2>/dev/null || true
```

Services:

| Service | Host port | Notes |
|---------|-----------|--------|
| `app` | 8080 → 8000 | nginx + PHP-FPM (not `artisan serve`) |
| `queue` | — | `queue:work` (required for tenant create bootstrap jobs) |
| `postgres` | 5433 → 5432 | Central DB `erp_central` (5433 avoids clash with local Postgres) |
| `redis` | (internal) | Cache + queue |
| `frontend` | 3000 | Profile `with-frontend` only |

> **Performance note:** The previous `php artisan serve` process handles **one request at a time**. The UI often fires several API calls on navigation, so they queued and felt slow only under Docker. The app container now runs **nginx + PHP-FPM** so those requests run concurrently.

Generate a real `APP_KEY` once and put it in a `.env` next to compose (or export it) so `app` and `queue` share the same key:

```bash
# On a machine with PHP/Laravel available:
php artisan key:generate --show
# Then: APP_KEY=base64:... docker compose up -d
```

## Migrations and tenancy

Stancl tenancy with PostgreSQL uses:

1. **Central database** (`erp_central`, schema `public`) — tenants, domains, modules (`php artisan migrate`)
2. **Per-tenant schemas** — created when a tenant is created (`PostgreSQLSchemaManager` + migrate jobs)

Typical first boot after Compose is healthy:

```bash
# Seed demo tenant + owner (queue worker must be running)
docker compose exec app php artisan db:seed --force

# Health (Compose publishes API on host 8080)
curl -s http://localhost:8080/api/health
```

Demo tenant (`TenantSeeder`): open **http://tenant.localhost:3000**, login `tenant@gmail.com` / `12345678`.

Central domains default to `app.localhost,localhost,127.0.0.1` (see `CENTRAL_DOMAIN`).  
Tenant APIs are identified by **hostname** (e.g. `tenant.localhost`). Map hosts in `/etc/hosts`:

```text
127.0.0.1 app.localhost
127.0.0.1 tenant.localhost
```

### Frontend “Network Error”

Docker frontend builds with `NODE_ENV=production`. URL helpers must honor `NEXT_PUBLIC_*` (see `system_frontend/lib/config.js`). Rebuild the frontend image after changing those build args. Browser calls must use host port **8080**, not 8000.
## Environment checklist (names only — no secrets in git)

See [`.env.example`](../.env.example). Important groups:

| Area | Variables |
|------|-----------|
| App | `APP_KEY`, `APP_URL`, `APP_ENV` |
| Central tenancy | `CENTRAL_DOMAIN` |
| Database | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| Redis / queue | `REDIS_*`, `QUEUE_CONNECTION`, `CACHE_STORE` |
| Auth CORS | `SANCTUM_STATEFUL_DOMAINS` |
| Mail / files | `MAIL_*`, `FILESYSTEM_DISK` |

Frontend (sibling repo) uses `NEXT_PUBLIC_*` — see `system_frontend/.env.example`.

## CI

On every push/PR to `main`:

- Composer validate + audit
- PHPUnit
- Pint
- PHPStan (fails CI on any finding)

Local equivalent: `composer ci:full`

## Deploy stub

Workflow: `.github/workflows/deploy-staging.yml` (`workflow_dispatch` only).

It validates Compose and builds the API image. It does **not** push to a registry or SSH to a host. When a host exists (Option C), extend that workflow with secrets and deploy steps.

## Future host checklist (Option C)

1. Choose host (VPS, Forge, Railway, etc.) and container registry  
2. Set production secrets (`APP_KEY`, DB, Redis, domains)  
3. Point DNS: central domain + `*.tenant` wildcard  
4. TLS / reverse proxy  
5. Run central migrate; create tenants; keep **queue workers** running  
6. Wire frontend `NEXT_PUBLIC_*` to real API URLs  
7. Backups for central + all tenant databases  
