#!/usr/bin/env bash
# Container entrypoint for the AvalynxOffice production image.
#
# Startup sequence (idempotent, safe on every restart):
#   1. Wait until the DATABASE_URL is reachable.
#   2. Run the migrations bundled with this image version (only pending
#      ones are applied; Doctrine tracks executed versions).
#   3. Create the env-defined administrator (OFFICE_ADMIN_EMAIL /
#      OFFICE_ADMIN_PASSWORD) when that email is not present yet.
#   4. Hand over to the base image entrypoint (Apache/supervisor).
set -euo pipefail

cd /app

if [ -z "${APP_SECRET:-}" ]; then
    echo '[avalynxoffice] APP_SECRET is not set. Provide it via the environment (e.g. -e APP_SECRET=$(openssl rand -hex 16)).' >&2
    exit 1
fi

run_as_application() {
    su -s /bin/bash application -c "$*"
}

echo '[avalynxoffice] Waiting for the database ...'
attempt=0
until run_as_application 'php bin/console dbal:run-sql "SELECT 1"' >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "${attempt}" -ge 30 ]; then
        echo '[avalynxoffice] Database not reachable, giving up.' >&2
        exit 1
    fi
    sleep 2
done

echo '[avalynxoffice] Running database migrations ...'
run_as_application 'php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'

# The Symfony container cache is considered always-fresh in prod and the var
# directory is a persistent volume: after an image update the stale cache of
# the previous image version must be rebuilt (it contains the compiled
# configuration, e.g. new trusted_proxies settings).
echo '[avalynxoffice] Rebuilding the application cache ...'
run_as_application 'php bin/console cache:clear --no-warmup 2>&1' || true
run_as_application 'php bin/console cache:warmup --quiet 2>&1' || true

if [ -n "${OFFICE_ADMIN_EMAIL:-}" ] && [ -n "${OFFICE_ADMIN_PASSWORD:-}" ]; then
    echo '[avalynxoffice] Ensuring the configured administrator exists ...'
    run_as_application 'php bin/console app:user:create --email="$OFFICE_ADMIN_EMAIL" --password="$OFFICE_ADMIN_PASSWORD"'
fi

echo '[avalynxoffice] Starting web server ...'
exec /entrypoint "$@"
