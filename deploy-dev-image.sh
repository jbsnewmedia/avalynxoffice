#!/usr/bin/env bash
#
# deploy-dev-image.sh - AvalynxOffice-Image aus dem lokalen Quellstand bauen
# und den Prod-Stack (avalynxofficedocker, Port 8090) damit starten.
#
#   ./deploy-dev-image.sh            Office-Image bauen + Stack starten
#   ./deploy-dev-image.sh --eas      zusaetzlich das EAS-Image bauen
#   ./deploy-dev-image.sh --help
#
# Ablauf:
#   1. docker build aus dem aktuellen Quellstand (docker/prod/Dockerfile,
#      inkl. per-Dockerfile .dockerignore)           -> Tag ":dev"
#   2. OFFICE_TAG (bzw. EAS_TAG) in ../avalynxofficedocker/.env = "dev"
#      setzen, damit der Stack dauerhaft das frisch gebaute Image nutzt
#      (auch bei spaeterem ./core.sh up)
#   3. docker compose up -d - der Container-Entrypoint wartet auf die DB,
#      spielt Migrationen ein und legt den Admin-User an
#   4. Warten auf den Healthcheck + Kurzcheck der Login-Seite
#
set -euo pipefail

SRC_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STACK_DIR_REL="${SRC_ROOT}/../avalynxofficedocker"

OFFICE_IMAGE="jbsnewmedia/avalynxoffice"
EAS_IMAGE="jbsnewmedia/avalynxofficeeas"
TAG="dev"

C()   { printf '\033[%sm' "$1"; }
BOLD=$(C 1); GREEN=$(C 32); RED=$(C 31); YELLOW=$(C 33); R=$(C 0)
ok()   { printf "  ${GREEN}OK${R}   %s\n" "$*"; }
warn() { printf "  ${YELLOW}WARN${R} %s\n" "$*"; }
fail() { printf "  ${RED}FAIL${R} %s\n" "$*"; }

usage() {
    cat <<EOF
deploy-dev-image.sh - Image aus dem lokalen Quellstand bauen und starten

Usage:
  ./deploy-dev-image.sh [--eas]

Options:
  --eas    zusaetzlich das EAS-Image (avalynxofficeeas) bauen und aktivieren
  --help   diese Hilfe
EOF
}

BUILD_EAS=0
for arg in "$@"; do
    case "$arg" in
        --eas)     BUILD_EAS=1 ;;
        -h|--help) usage; exit 0 ;;
        *)         fail "unbekannte Option: $arg"; usage; exit 1 ;;
    esac
done

if [ ! -f "$STACK_DIR_REL/compose.yaml" ]; then
    fail "Prod-Stack nicht gefunden: $STACK_DIR_REL"
    exit 1
fi
STACK_DIR="$(cd "$STACK_DIR_REL" && pwd)"
STACK_ENV="$STACK_DIR/.env"

if [ ! -f "$STACK_ENV" ]; then
    fail ".env fehlt im Prod-Stack: $STACK_ENV"
    exit 1
fi

# --- 1. Images bauen -------------------------------------------------------

echo "${BOLD}[1/4] Office-Image bauen: ${OFFICE_IMAGE}:${TAG}${R}"
docker build -f "$SRC_ROOT/docker/prod/Dockerfile" -t "${OFFICE_IMAGE}:${TAG}" "$SRC_ROOT"
ok "Office-Image gebaut"

if [ "$BUILD_EAS" = "1" ]; then
    EAS_ROOT="${SRC_ROOT}/../avalynxofficeeas"
    if [ ! -f "$EAS_ROOT/Dockerfile" ]; then
        fail "EAS-Quellverzeichnis nicht gefunden: $EAS_ROOT"
        exit 1
    fi
    echo "${BOLD}[1/4] EAS-Image bauen: ${EAS_IMAGE}:${TAG}${R}"
    docker build -t "${EAS_IMAGE}:${TAG}" "$EAS_ROOT"
    ok "EAS-Image gebaut"
fi

# --- 2. Stack auf die Dev-Tags zeigen lassen -------------------------------

set_env_var() {
    # set_env_var KEY VALUE  - schreibt KEY=VALUE idempotent in die Stack-.env
    local key="$1" val="$2"
    if grep -qE "^${key}=" "$STACK_ENV" 2>/dev/null; then
        sed -i "s|^${key}=.*|${key}=${val}|" "$STACK_ENV"
    else
        printf '%s=%s\n' "$key" "$val" >> "$STACK_ENV"
    fi
}

echo "${BOLD}[2/4] Stack-Image-Tags setzen (${STACK_ENV})${R}"
set_env_var OFFICE_TAG "$TAG"
[ "$BUILD_EAS" = "1" ] && set_env_var EAS_TAG "$TAG"
ok "OFFICE_TAG=${TAG}$( [ "$BUILD_EAS" = "1" ] && printf ', EAS_TAG=%s' "$TAG" )"

# --- 3. Stack starten ------------------------------------------------------

echo "${BOLD}[3/4] Stack starten (Migrationen/Admin uebernimmt der Entrypoint)${R}"
# Kompilierten Prod-Cache des Vorgaenger-Images verwerfen - der ist nach
# einem Image-Update unweigerlich veraltet und kann ggf. nicht mal booten.
rm -rf "$STACK_DIR/office-var/cache/prod" 2>/dev/null || true
( cd "$STACK_DIR" && docker compose up -d )

# --- 4. Warten bis bereit --------------------------------------------------

echo "${BOLD}[4/4] Warten auf Office-Healthcheck ...${R}"
container="$( cd "$STACK_DIR" && docker compose ps -q office )"
if [ -z "$container" ]; then
    fail "Office-Container nicht gefunden (docker compose ps)."
    exit 1
fi

status="unknown"
for _ in $(seq 1 60); do
    status="$(docker inspect -f '{{.State.Health.Status}}' "$container" 2>/dev/null || echo unknown)"
    [ "$status" = "healthy" ] && break
    [ "$status" = "unknown" ] && { docker ps --filter "id=$container" --format '{{.Status}}' | grep -q 'Up' || break; }
    sleep 5
done

if [ "$status" != "healthy" ]; then
    fail "Office-Container nicht healthy (Status: $status). Logs: cd $STACK_DIR && docker compose logs office"
    exit 1
fi
ok "Office-Container healthy"

HTTP_PORT="$(grep -E '^HTTP_PORT=' "$STACK_ENV" | tail -1 | cut -d= -f2-)"
HTTP_PORT="${HTTP_PORT:-80}"
code="$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:${HTTP_PORT}/login" --max-time 10 || true)"
if [ "$code" != "200" ] && [ "$code" != "302" ]; then
    warn "Login-Seite antwortet mit HTTP $code (Container ist aber healthy)."
else
    ok "Login erreichbar: http://localhost:${HTTP_PORT}/ (HTTP $code)"
fi

echo
ok "Deploy fertig - Stack laeuft mit dem frisch gebauten Image (${TAG})."
