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
#      OFFICE_ADMIN_PASSWORD) when that email is not present yet.
#   5. Hand over to the base image entrypoint (Apache/supervisor).
set -euo pipefail

cd /app

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
# grants are (re-)applied here on every start. The DO block is a no-op when
# the role does not exist; the role name is validated before interpolation.
if [ -n "${EAS_DB_USER:-}" ]; then
    if ! printf '%s' "${EAS_DB_USER}" | grep -Eq '^[a-z_][a-z0-9_]{0,62}$'; then
        echo "[avalynxoffice] EAS_DB_USER must match ^[a-z_][a-z0-9_]*$ (got: ${EAS_DB_USER})." >&2
        exit 1
    fi
    echo "[avalynxoffice] Granting EAS database privileges to role ${EAS_DB_USER} ..."
    run_as_application php bin/console dbal:run-sql "
        DO \$\$
        DECLARE
            r text := '${EAS_DB_USER}';
            t text;
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) THEN
                RAISE NOTICE 'role % does not exist, skipping EAS grants', r;
                RETURN;
            END IF;
            EXECUTE format('GRANT USAGE ON SCHEMA public TO %I', r);
            EXECUTE format('GRANT SELECT ON ALL TABLES IN SCHEMA public TO %I', r);
            EXECUTE format('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO %I', r);
            EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO %I', r);
            EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO %I', r);
            -- Mail, address book and calendar tables written by the EAS service.
            FOREACH t IN ARRAY ARRAY[
                'email', 'email_folder', 'email_address_link', 'email_body_link', 'email_attachment_link',
                'body', 'attachment', 'address', 'account_address', 'folder', 'account_folder',
                'calendar_event'
            ] LOOP
                IF to_regclass('public.' || quote_ident(t)) IS NOT NULL THEN
                    EXECUTE format('GRANT INSERT, UPDATE, DELETE ON TABLE public.%I TO %I', t, r);
                END IF;
            END LOOP;
            -- Column-restricted updates: inbox folder detection and the
            -- last-authentication timestamp of a user.
            IF to_regclass('public.account') IS NOT NULL THEN
                EXECUTE format('GRANT UPDATE (inbox_folder_id) ON TABLE public.account TO %I', r);
            END IF;
            IF to_regclass('public.app_user') IS NOT NULL THEN
                EXECUTE format('GRANT UPDATE (last_auth_at) ON TABLE public.app_user TO %I', r);
            END IF;
            IF to_regclass('public.app_user_app_password') IS NOT NULL THEN
                EXECUTE format('GRANT UPDATE (last_used_at) ON TABLE public.app_user_app_password TO %I', r);
            END IF;
        END
        \$\$;
    "
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
fi
# The credentials are only needed for the bootstrap above; do not leak them
# into the web server process tree.
unset OFFICE_ADMIN_PASSWORD OFFICE_ADMIN_EMAIL

echo '[avalynxoffice] Starting web server ...'
exec /entrypoint "$@"
