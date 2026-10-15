#!/usr/bin/env bash
#
# core.sh - control the mailer stack (web + postgres).
#
#   ./core.sh up           build (if needed) + start
#   ./core.sh down         stop & remove
#   ./core.sh build        build image
#   ./core.sh restart      down -> up
#   ./core.sh status       container status + supervisord programs
#   ./core.sh logs [svc]   follow container logs (svc: all|web|db)
#   ./core.sh shell        bash inside the container (user: application)
#   ./core.sh root-shell   bash inside the container (user: root)
#   ./core.sh exec -- CMD  run CMD inside the container
#   ./core.sh help
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

# Container-Name ist bei docker compose "<projekt>-<service>-<index>";
# das Projekt ergibt sich aus dem Verzeichnisnamen (z.B. "mailer" -> "avalynxoffice-web-1").
CONTAINER="$(basename "$ROOT" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9_-]/-/g')-web-1"

# Host ports fuer docker compose (gleiche Variablen wie in docker-compose.yml).
# Defaults 80/443/5432; .env ueberschreibt sie, echte Umgebungsvariablen
# gewinnen weiterhin ueber .env.
for var in HTTP_PORT HTTPS_PORT DB_PORT; do
    val="$(grep -E "^${var}=" .env 2>/dev/null | tail -1 | cut -d= -f2- || true)"
    if [ -z "${!var:-}" ] && [ -n "$val" ]; then export "$var=$val"; fi
done

C()   { printf '\033[%sm' "$1"; }
BOLD=$(C 1); GREEN=$(C 32); RED=$(C 31); YELLOW=$(C 33); R=$(C 0)
ok()   { printf "  ${GREEN}OK${R}   %s\n" "$*"; }
fail() { printf "  ${RED}FAIL${R} %s\n" "$*"; }

usage() {
    cat <<EOF
core.sh - control the mailer stack (web: webdevops/php-apache-dev, db: postgres:16)

Commands:
  up                    down -> build -> up -d (clean rebuild)
  down                  stop & remove
  build                 build image only
  restart               down -> up
  status                containers + supervisord programs (web)
  logs [svc]            follow logs (svc: all|web|db, default: all)
  shell                 bash as application in $CONTAINER
  root-shell            bash as root in $CONTAINER
  exec -- CMD           run CMD in $CONTAINER
  composer CMD          run composer in $CONTAINER
  console CMD           run php bin/console in $CONTAINER
  ports                 show port mappings
  help                  this help
EOF
}

# Bind-Mount-Ziele auf dem Host vorbereiten (Verzeichnisse muessen vor dem
# Mount existieren, sonst erstellt Docker sie als root statt als Host-User).
ensure_host_dirs() {
    mkdir -p docker/postgres/init docker/postgres/data
}

case "${1:-help}" in
    up|start)
        ensure_host_dirs
        docker compose down >/dev/null 2>&1 || true
        docker compose up -d --build
        docker compose ps
        ;;
    down|stop)
        docker compose down
        ;;
    build)
        ensure_host_dirs
        docker compose build
        ;;
    restart)
        ensure_host_dirs
        docker compose down >/dev/null 2>&1 || true
        docker compose up -d --build
        ;;
    status)
        docker compose ps
        echo ""
        if docker inspect -f '{{.State.Running}}' "$CONTAINER" >/dev/null 2>&1; then
            docker exec --user=root "$CONTAINER" supervisorctl status 2>/dev/null \
                || fail "supervisord not reachable or programs stopped"
        fi
        ;;
    logs)
        svc="${2:-all}"
        if [ "$svc" = "all" ]; then
            docker compose logs -f
        else
            docker compose logs -f "$svc"
        fi
        ;;
    shell|bash)
        docker exec -it --user=application -w /app "$CONTAINER" bash
        ;;
    root-shell|root)
        docker exec -it --user=root -w /app "$CONTAINER" bash
        ;;
    exec)
        shift || true
        if [ "${1:-}" = "--" ]; then shift; fi
        if [ "$#" -eq 0 ]; then
            fail "exec needs a command, e.g. ./core.sh exec -- php -v"
            exit 1
        fi
        docker exec --user=application -w /app "$CONTAINER" "$@"
        ;;
    composer)
        shift || true
        docker exec --user=application -w /app "$CONTAINER" composer "$@"
        ;;
    console)
        shift || true
        docker exec --user=application -w /app "$CONTAINER" php bin/console "$@"
        ;;
    ports)
        echo "HTTP:   localhost:${HTTP_PORT:-80}"
        echo "HTTPS:  localhost:${HTTPS_PORT:-443}"
        echo "DB:     localhost:${DB_PORT:-5432} (postgres:16, db=my_database user=my_user)"
        ;;
    help|-h|--help|"")
        usage
        ;;
    *)
        usage
        exit 1
        ;;
esac
