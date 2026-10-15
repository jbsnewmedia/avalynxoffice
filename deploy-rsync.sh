#!/usr/bin/env bash
set -euo pipefail

# =========================
# Konfiguration
# =========================
SSH_USER="${SSH_USER:-root}"
SSH_HOST="${SSH_HOST:-10.11.6.2}"
SSH_PORT="${SSH_PORT:-22}"
SSH_KEY="${SSH_KEY:-}"
REMOTE_BASE_DIR="${REMOTE_BASE_DIR:-/srv/docker/office}"

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
ssh "${SSH_OPTS[@]}" "${SSH_USER}@${SSH_HOST}" bash -s << 'REMOTE_CMDS'
set -euo pipefail
cd /srv/docker/office
# applikation (uid 1000 im webdevops-image) braucht Schreibrechte auf var
docker exec office-web-1 sh -c "chown -R application:application /app/var 2>/dev/null || true"
rm -rf var/cache/prod
echo "✅ Remote bereit."
REMOTE_CMDS

echo "🎉 Rsync-Deploy fertig."
