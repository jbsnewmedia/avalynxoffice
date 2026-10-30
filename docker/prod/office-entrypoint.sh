#!/usr/bin/env bash
# Container entrypoint for the AvalynxOffice production image.
#
# Startup sequence (idempotent, safe on every restart):
#   1. Wait until the DATABASE_URL is reachable.
#   2. Run the migrations bundled with this image version (only pending
#      ones are applied; Doctrine tracks executed versions).
#   3. Grant the (optional) least-privilege EAS database role access to the
#      tables it needs (idempotent, only when EAS_DB_USER is set).
#   4. Create the env-defined administrator (OFFICE_ADMIN_EMAIL /
#      OFFICE_ADMIN_PASSWORD, or *_FILE pointing to a secret file) when that
#      email is not present yet.
#   5. Hand over to the base image entrypoint (Apache/supervisor).
set -euo pipefail

cd /app

# Docker/Compose secrets support: VAR_FILE takes precedence over VAR and is
# read from the given file (trailing newline stripped). Both set is an error
# to avoid silently using the wrong value.
read_secret_file() {
    local var="$1" file_var="$1_FILE" file
    file="${!file_var:-}"
    if [ -z "${file}" ]; then
        return 0
    fi
    if [ -n "${!var:-}" ]; then
        echo "[avalynxoffice] Both ${var} and ${file_var} are set; use only one of them." >&2
        exit 1
    fi
    if [ ! -r "${file}" ]; then
        echo "[avalynxoffice] ${file_var} points to ${file}, which is not readable." >&2
        exit 1
    fi
    printf -v "${var}" '%s' "$(cat "${file}")"
    export "${var?}"
}

read_secret_file OFFICE_ADMIN_EMAIL
read_secret_file OFFICE_ADMIN_PASSWORD
ADMIN_PASSWORD_FROM_ENV=0
if [ -n "${OFFICE_ADMIN_PASSWORD:-}" ] && [ -z "${OFFICE_ADMIN_PASSWORD_FILE:-}" ]; then
    ADMIN_PASSWORD_FROM_ENV=1
fi

if [ -z "${APP_SECRET:-}" ]; then
    echo '[avalynxoffice] APP_SECRET is not set. Provide it via the environment (e.g. -e APP_SECRET=$(openssl rand -hex 16)).' >&2
    exit 1
fi

if [ -z "${AVALYNXOFFICE_ENCRYPTION_KEY:-}" ]; then
    echo '[avalynxoffice] AVALYNXOFFICE_ENCRYPTION_KEY is not set. Provide it via the environment (e.g. -e AVALYNXOFFICE_ENCRYPTION_KEY=$(openssl rand -base64 32)).' >&2
    exit 1
fi

# Run a command as the unprivileged application user. The arguments are passed
# verbatim (no shell re-parsing), so values containing quotes or shell
# metacharacters are safe.
run_as_application() {
    gosu application "$@"
}

echo '[avalynxoffice] Waiting for the database ...'
attempt=0
until run_as_application php bin/console dbal:run-sql 'SELECT 1' >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "${attempt}" -ge 30 ]; then
        echo '[avalynxoffice] Database not reachable, giving up.' >&2
        exit 1
    fi
    sleep 2
done

echo '[avalynxoffice] Running database migrations ...'
run_as_application php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Least privilege for the ActiveSync service: the EAS container connects with
# its own database role (created by the postgres init script of the Compose
# stack). The tables only exist after the migrations above, therefore the
# grants are (re-)applied here on every start. The grant statements live in
# /usr/local/lib/avalynxoffice/eas-grants.sql (single source of truth, also
# used by `office-grant-eas` for manual re-application). A missing role is
# skipped with a notice; the role name is validated by the script.
if [ -n "${EAS_DB_USER:-}" ]; then
    /usr/local/bin/office-grant-eas "${EAS_DB_USER}"
fi

# The Symfony container cache is considered always-fresh in prod and the var
# directory is a persistent volume: after an image update the stale cache of
# the previous image version must be rebuilt (it contains the compiled
# configuration, e.g. new trusted_proxies settings).
echo '[avalynxoffice] Rebuilding the application cache ...'
# The cache must be rebuilt after an image update, but a too-stale cache
# (e.g. from a previous version with different class names) cannot even boot
# the kernel for cache:clear. In that case remove it and warm up from scratch.
# The cache directory follows APP_ENV (prod image: prod, dev image: dev).
CACHE_DIR="/app/var/cache/${APP_ENV:-prod}"
run_as_application rm -rf "${CACHE_DIR}"
if ! run_as_application php bin/console cache:clear --no-warmup 2>&1; then
    echo '[avalynxoffice] cache:clear failed, removing the stale cache directory ...'
    run_as_application rm -rf "${CACHE_DIR}"
fi
if ! run_as_application php bin/console cache:warmup --quiet 2>&1; then
    echo '[avalynxoffice] cache:warmup failed, retrying with a fresh cache directory ...'
    run_as_application rm -rf "${CACHE_DIR}"
    run_as_application php bin/console cache:warmup --quiet 2>&1
fi

if [ -n "${OFFICE_ADMIN_EMAIL:-}" ] && [ -n "${OFFICE_ADMIN_PASSWORD:-}" ]; then
    echo '[avalynxoffice] Ensuring the configured administrator exists ...'
    # The command reads OFFICE_ADMIN_EMAIL / OFFICE_ADMIN_PASSWORD from the
    # environment itself, so the password never appears on a command line
    # (visible in `ps`) and is never re-parsed by a shell.
    run_as_application php bin/console app:user:create --admin
    if [ "${ADMIN_PASSWORD_FROM_ENV}" = '1' ]; then
        echo '[avalynxoffice] WARNING: OFFICE_ADMIN_PASSWORD is passed as a plain environment variable. The administrator exists now; remove it from .env (or switch to OFFICE_ADMIN_PASSWORD_FILE with a Compose secret).' >&2
    fi
fi
# The credentials are only needed for the bootstrap above; do not leak them
# into the web server process tree.
unset OFFICE_ADMIN_PASSWORD OFFICE_ADMIN_EMAIL OFFICE_ADMIN_PASSWORD_FILE OFFICE_ADMIN_EMAIL_FILE

echo '[avalynxoffice] Starting web server ...'
exec /entrypoint "$@"
