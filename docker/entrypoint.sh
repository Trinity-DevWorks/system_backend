#!/bin/sh
set -e

cd /var/www/html

if [ ! -f .env ] && [ -f .env.example ]; then
  cp .env.example .env
fi

# Compose injects DB_*/APP_* into the process environment, but .env.example defaults
# to sqlite. php artisan serve has been observed resolving DB from .env as sqlite
# even when the container env has pgsql — keep .env aligned with runtime env.
upsert_env() {
  key="$1"
  val="$2"
  if [ -z "$val" ]; then
    return 0
  fi
  if [ ! -f .env ]; then
    echo "${key}=${val}" > .env
    return 0
  fi
  if grep -qE "^${key}=" .env; then
    awk -v k="$key" -v v="$val" '
      BEGIN { done = 0 }
      $0 ~ "^" k "=" {
        print k "=" v
        done = 1
        next
      }
      { print }
      END { if (!done) print k "=" v }
    ' .env > .env.tmp && mv .env.tmp .env
  else
    echo "${key}=${val}" >> .env
  fi
}

upsert_env APP_ENV "${APP_ENV}"
upsert_env APP_DEBUG "${APP_DEBUG}"
upsert_env APP_URL "${APP_URL}"
upsert_env APP_KEY "${APP_KEY}"
upsert_env CENTRAL_DOMAIN "${CENTRAL_DOMAIN}"
upsert_env DB_CONNECTION "${DB_CONNECTION}"
upsert_env DB_HOST "${DB_HOST}"
upsert_env DB_PORT "${DB_PORT}"
upsert_env DB_DATABASE "${DB_DATABASE}"
upsert_env DB_USERNAME "${DB_USERNAME}"
upsert_env DB_PASSWORD "${DB_PASSWORD}"
upsert_env DB_SCHEMA "${DB_SCHEMA}"
upsert_env REDIS_CLIENT "${REDIS_CLIENT}"
upsert_env REDIS_HOST "${REDIS_HOST}"
upsert_env REDIS_PORT "${REDIS_PORT}"
upsert_env CACHE_STORE "${CACHE_STORE}"
upsert_env QUEUE_CONNECTION "${QUEUE_CONNECTION}"
upsert_env SESSION_DRIVER "${SESSION_DRIVER}"
upsert_env LOG_CHANNEL "${LOG_CHANNEL}"

# Generate app key when missing (compose / first boot).
if [ -z "${APP_KEY}" ] || [ "${APP_KEY}" = "base64:" ]; then
  if ! grep -qE '^APP_KEY=base64:.+' .env 2>/dev/null; then
    php artisan key:generate --force --no-interaction || true
  fi
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force --no-interaction
fi

exec "$@"
