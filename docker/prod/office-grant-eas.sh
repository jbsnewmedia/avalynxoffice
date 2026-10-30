#!/usr/bin/env bash
# (Re-)applies the least-privilege grants for the ActiveSync (EAS) database
# role defined in /usr/local/lib/avalynxoffice/eas-grants.sql.
#
# Called by office-entrypoint after every migration run and manually via
#   docker compose exec office office-grant-eas
# (`core.sh grant-eas` of the Compose stack). The role name is taken from
# EAS_DB_USER (or the first argument); the SQL file is the single source of
# truth for the granted tables and columns.
#
# Exit codes: 0 grants applied (or role missing → skipped with a notice),
# 2 no role configured, 1 invalid role name or database error.
set -euo pipefail

GRANTS_SQL="${OFFICE_EAS_GRANTS_SQL:-/usr/local/lib/avalynxoffice/eas-grants.sql}"
role="${1:-${EAS_DB_USER:-}}"

if [ -z "${role}" ]; then
    echo '[avalynxoffice] EAS_DB_USER is not set, nothing to grant.' >&2
    exit 2
fi

# The name is interpolated into a SQL string literal below; restrict it to
# the unquoted PostgreSQL identifier syntax so that no quoting is possible.
if ! printf '%s' "${role}" | grep -Eq '^[a-z_][a-z0-9_]{0,62}$'; then
    echo "[avalynxoffice] EAS_DB_USER must match ^[a-z_][a-z0-9_]*$ (got: ${role})." >&2
    exit 1
fi

if [ ! -r "${GRANTS_SQL}" ]; then
    echo "[avalynxoffice] Grant file ${GRANTS_SQL} not found." >&2
    exit 1
fi

cd /app

run_as_application() {
    if [ "$(id -u)" = '0' ]; then
        gosu application "$@"
    else
        "$@"
    fi
}

echo "[avalynxoffice] Granting EAS database privileges to role ${role} ..."
# Both statements are sent in one round trip (same session), so the custom
# setting is visible to the DO block. dbal:run-sql passes the string to
# PDO::exec() without parameters, which allows multiple statements.
run_as_application php bin/console dbal:run-sql "$(printf "SET avalynxoffice.eas_role = '%s';\n" "${role}"; cat "${GRANTS_SQL}")"
