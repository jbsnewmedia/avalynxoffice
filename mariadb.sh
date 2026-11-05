#!/usr/bin/env bash
#
# mariadb.sh - run the MySQL/MariaDB platform variant of the test suite.
#
# The main docker-compose.yml is PostgreSQL-only. This script manages a
# separate MariaDB stack (docker-compose.mariadb.yml, own compose project)
# and runs the suite inside a short-lived runner container attached to it:
#
#   ./mariadb.sh up            start the MariaDB stack (waits until ready)
#   ./mariadb.sh down          stop & remove the MariaDB stack
#   ./mariadb.sh reset         down -> drop data volume -> up (fresh database)
#   ./mariadb.sh status        container status
#   ./mariadb.sh logs          follow MariaDB logs
#   ./mariadb.sh mysql ARGS    mysql client inside the stack
#   ./mariadb.sh console CMD   php bin/console against MariaDB, e.g.
#                              ./mariadb.sh console doctrine:schema:validate
#   ./mariadb.sh test [ARGS]   run the suite against MariaDB (default:
#                              composer test; ARGS replace it, e.g. vendor
#                              phpunit calls for a single filter)
#   ./mariadb.sh help
#
# Runner image: the local compose web image (avalynxoffice-web) when built,
# otherwise the published dev image jbsnewmedia/avalynxoffice-dev. Override
# with RUNNER_IMAGE=... for custom setups.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

PROJECT="avalynxoffice-mariadb"
COMPOSE=(docker compose -f docker-compose.mariadb.yml -p "$PROJECT")
NETWORK="${PROJECT}_default"
DATABASE_URL="mysql://root:my_root_password@mariadb:3306/my_database?serverVersion=10.6.23-MariaDB&charset=utf8mb4"

for var in MARIADB_PORT; do
    val="$(grep -E "^${var}=" .env 2>/dev/null | tail -1 | cut -d= -f2- || true)"
    if [ -z "${!var:-}" ] && [ -n "$val" ]; then export "$var=$val"; fi
done

C()   { printf '\033[%sm' "$1"; }
BOLD=$(C 1); GREEN=$(C 32); RED=$(C 31); YELLOW=$(C 33); R=$(C 0)
ok()   { printf "  ${GREEN}OK${R}   %s\n" "$*"; }
fail() { printf "  ${RED}FAIL${R} %s\n" "$*"; }

runner_image() {
    if [ -n "${RUNNER_IMAGE:-}" ]; then
        echo "$RUNNER_IMAGE"
    elif docker image inspect avalynxoffice-web >/dev/null 2>&1; then
        echo "avalynxoffice-web"
    else
        echo "jbsnewmedia/avalynxoffice-dev"
    fi
}

# The MariaDB image initializes its datadir with a temporary server on first
# start; the compose healthcheck may pass while the final server is still
# coming up. Wait for two consecutive successful queries (the temporary
# init server is stopped and restarted once) before handing over.
wait_ready() {
    local tries=60
    local ok=0
    for _ in $(seq 1 "$tries"); do
        if docker run --rm --network "$NETWORK" mariadb:10.6 \
            mariadb -h mariadb -uroot -pmy_root_password -e 'SELECT 1' >/dev/null 2>&1; then
            ok=$((ok + 1))
            if [ "$ok" -ge 2 ]; then
                return 0
            fi
            sleep 3
        else
            ok=0
            sleep 1
        fi
    done
    return 1
}

# Runs a command inside a fresh runner container attached to the MariaDB
# network, as the application user (uid 1000), with the repo mounted to /app.
runner() {
    docker run --rm --user 1000:1000 --entrypoint /bin/bash \
        --network "$NETWORK" \
        -v "$ROOT":/app -w /app \
        -e DATABASE_URL="$DATABASE_URL" \
        "$(runner_image)" -lc "$*"
}

usage() {
    sed -n '2,25p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

case "${1:-help}" in
    up)
        mkdir -p var
        "${COMPOSE[@]}" up -d --wait || { fail "MariaDB stack did not become healthy"; exit 1; }
        wait_ready || { fail "MariaDB did not accept queries"; exit 1; }
        ok "MariaDB stack is up (host port ${MARIADB_PORT:-3307})"
        ;;
    down)
        "${COMPOSE[@]}" down
        ok "MariaDB stack stopped"
        ;;
    reset)
        "${COMPOSE[@]}" down -v
        "${COMPOSE[@]}" up -d --wait || { fail "MariaDB stack did not become healthy"; exit 1; }
        wait_ready || { fail "MariaDB did not accept queries"; exit 1; }
        ok "MariaDB stack reset (fresh database)"
        ;;
    status)
        "${COMPOSE[@]}" ps
        ;;
    logs)
        "${COMPOSE[@]}" logs -f
        ;;
    mysql)
        shift
        docker run --rm --network "$NETWORK" mariadb:10.6 \
            mariadb -h mariadb -uroot -pmy_root_password my_database -e "$*"
        ;;
    console)
        shift
        runner "php bin/console $*"
        ;;
    test)
        shift
        "${COMPOSE[@]}" up -d --wait >/dev/null
        wait_ready || { fail "MariaDB did not accept queries"; exit 1; }
        CMD="${*:-composer test}"
        runner "$CMD"
        ;;
    help|--help|-h)
        usage
        ;;
    *)
        echo "Unknown command: $1" >&2
        usage
        exit 1
        ;;
esac
