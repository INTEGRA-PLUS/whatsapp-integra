#!/usr/bin/env bash
# Entrypoint for whatsapp-integra runtime image.
# Performs one-time bootstrap (env validation, migrations, cache warm-up)
# then hands off via `exec "$@"` so PID 1 is the actual workload.
set -Eeuo pipefail

log() { printf '[entrypoint] %s\n' "$*"; }

cd /var/www/html

# ---------------------------------------------------------------------------
# Required environment
# ---------------------------------------------------------------------------
: "${APP_KEY:?APP_KEY must be set (run: php artisan key:generate --show)}"
: "${APP_ENV:?APP_ENV must be set (production|staging)}"

# Don't ship a publicly-readable .env into a container that already gets its
# config through real environment variables. Laravel reads $_ENV first, so we
# can safely skip the file entirely. If one was baked in by accident, remove it.
if [[ -f .env ]]; then
    log "removing baked-in .env (using container environment instead)"
    rm -f .env
fi

# ---------------------------------------------------------------------------
# Wait for dependencies (best-effort, non-fatal after timeout)
# ---------------------------------------------------------------------------
wait_for() {
    local host="$1" port="$2" name="$3" timeout="${4:-30}"
    log "waiting for ${name} at ${host}:${port} (timeout ${timeout}s)"
    local start now
    start=$(date +%s)
    until (echo > /dev/tcp/"${host}"/"${port}") >/dev/null 2>&1; do
        now=$(date +%s)
        if (( now - start >= timeout )); then
            log "WARN: ${name} not reachable after ${timeout}s — continuing"
            return 0
        fi
        sleep 1
    done
    log "${name} is up"
}

if [[ -n "${DB_HOST:-}" && "${DB_CONNECTION:-mysql}" != "sqlite" ]]; then
    wait_for "${DB_HOST}" "${DB_PORT:-3306}" "database" 60
fi
if [[ -n "${REDIS_HOST:-}" ]]; then
    wait_for "${REDIS_HOST}" "${REDIS_PORT:-6379}" "redis" 30
fi

# ---------------------------------------------------------------------------
# Storage symlink + permissions (volumes mount with root ownership by default)
# ---------------------------------------------------------------------------
mkdir -p storage/app/public \
         storage/framework/cache/data \
         storage/framework/sessions \
         storage/framework/views \
         storage/logs \
         bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwX            storage bootstrap/cache 2>/dev/null || true

if [[ ! -L public/storage ]]; then
    php artisan storage:link --force || true
fi

# ---------------------------------------------------------------------------
# Migrations + cache warm-up — gated, so worker/scheduler containers skip them
# ---------------------------------------------------------------------------
if [[ "${RUN_MIGRATIONS:-false}" == "true" ]]; then
    log "running database migrations"
    php artisan migrate --force --no-interaction
fi

if [[ "${WARM_CACHES:-true}" == "true" ]]; then
    log "warming Laravel caches (config/route/view/event)"
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache || true
fi

log "starting: $*"
exec "$@"
