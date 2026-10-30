#!/usr/bin/env bash
set -euo pipefail

# =========================
# Konfiguration
# =========================
# Pflicht-Variablen ohne Default (keine internen Hosts/Benutzer im Repo):
#   SSH_USER=deploy SSH_HOST=office.example.com REMOTE_BASE_DIR=/srv/docker/office ./deploy-rsync.sh
: "${SSH_USER:?SSH_USER muss gesetzt sein (Remote-Benutzer)}"
: "${SSH_HOST:?SSH_HOST muss gesetzt sein (Remote-Host)}"
: "${REMOTE_BASE_DIR:?REMOTE_BASE_DIR muss gesetzt sein (Zielverzeichnis auf dem Host)}"
SSH_PORT="${SSH_PORT:-22}"
SSH_KEY="${SSH_KEY:-}"
REMOTE_CONTAINER="${REMOTE_CONTAINER:-office-web-1}"

SSH_OPTS=(-p "$SSH_PORT" -o StrictHostKeyChecking=accept-new)
RSYNC_SSH="ssh -p $SSH_PORT -o StrictHostKeyChecking=accept-new"
if [[ -n "${SSH_KEY}" ]]; then
  SSH_OPTS+=(-i "$SSH_KEY")
  RSYNC_SSH="ssh -p $SSH_PORT -i $SSH_KEY -o StrictHostKeyChecking=accept-new"
fi

RSYNC_OPTS=(-az --delete
  --exclude '.git/'
  --exclude 'var/'
  --exclude 'data/'
  --exclude 'dist/'
  --exclude 'vendor-bin/'
  --exclude 'mail_dumps/'
  --exclude '.playwright-mcp/'
  --exclude '.idea/'
  --exclude '.php-cs-fixer.cache'
  --exclude '.phpunit.cache/'
  --exclude 'docker/postgres/data/'
  --exclude '.env.local'
  --exclude '.env.*.local'
  --exclude 'tests/'
  --exclude 'phpunit*.xml.dist'
  --exclude 'phpstan-global.neon'
  --exclude 'rector.php'
  --exclude '.php-cs-fixer.dist.php'
  --exclude '.clinerules'
  --exclude '.junie/'
  --exclude 'TODO'
  --exclude 'README.md'
  -e "$RSYNC_SSH")

echo "🚀 Rsync zu ${SSH_USER}@${SSH_HOST}:${REMOTE_BASE_DIR} ..."
rsync "${RSYNC_OPTS[@]}" \
  assets bin config migrations plugins public src templates translations vendor \
  composer.json composer.lock symfony.lock \
  "${SSH_USER}@${SSH_HOST}:${REMOTE_BASE_DIR}/"

echo "🔐 Rechte/Cache remote ..."
ssh "${SSH_OPTS[@]}" "${SSH_USER}@${SSH_HOST}" bash -s -- "$REMOTE_BASE_DIR" "$REMOTE_CONTAINER" << 'REMOTE_CMDS'
set -euo pipefail
cd "$1"
# applikation (uid 1000 im webdevops-image) braucht Schreibrechte auf var
docker exec "$2" sh -c "chown -R application:application /app/var 2>/dev/null || true"
rm -rf var/cache/prod
echo "✅ Remote bereit."
REMOTE_CMDS

echo "🎉 Rsync-Deploy fertig."
