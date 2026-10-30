#!/usr/bin/env bash
set -euo pipefail

# =========================
# Konfiguration
# =========================
DIST_DIR="${DIST_DIR:-dist}"
ARCHIVE_NAME="${ARCHIVE_NAME:-avalynxoffice-prod.tar.gz}"
ARCHIVE_PATH="$DIST_DIR/$ARCHIVE_NAME"

# SSH / Remote Einstellungen - Pflicht-Variablen ohne Default (keine
# internen Hosts/Benutzer im Repo):
#   SSH_USER=deploy SSH_HOST=office.example.com REMOTE_BASE_DIR=/srv/docker/office ./update.sh
: "${SSH_USER:?SSH_USER muss gesetzt sein (Remote-Benutzer)}"
: "${SSH_HOST:?SSH_HOST muss gesetzt sein (Remote-Host)}"
: "${REMOTE_BASE_DIR:?REMOTE_BASE_DIR muss gesetzt sein (Zielverzeichnis auf dem Host)}"
SSH_PORT="${SSH_PORT:-22}"
SSH_KEY="${SSH_KEY:-}"
REMOTE_DIST_DIR="${REMOTE_DIST_DIR:-$REMOTE_BASE_DIR/dist}"
REMOTE_UNPACK_DIR="${REMOTE_UNPACK_DIR:-$REMOTE_BASE_DIR}"

# Sicherheits-Optionen für SSH/SCP
# Sicherheits-Optionen für SSH/SCP
SSH_OPTS=(-p "$SSH_PORT" -o StrictHostKeyChecking=accept-new)
if [[ -n "${SSH_KEY}" ]]; then
  SSH_OPTS+=(-i "$SSH_KEY")
fi

# =========================
# Archiv bauen
# =========================

mkdir -p "$DIST_DIR"

# Zu packende Verzeichnisse
DIRS=(
  "assets"
  "bin"
  "config"
  "migrations"
  "public"
  "src"
  "templates"
  "translations"
  "vendor"
)

# Zu packende Dateien
FILES=(
  "composer.json"
  "composer.lock"
  "symfony.lock"
)

# Archiv erstellen
tar -czf "$ARCHIVE_PATH" "${DIRS[@]}" "${FILES[@]}"
echo "✅ Archiv erstellt: $ARCHIVE_PATH"

# =========================
# *** HIER — Upload & Remote Schritte
# =========================

# Upload (scp)
echo "🚀 Lade Archiv zu ${SSH_USER}@${SSH_HOST}:${REMOTE_DIST_DIR} hoch ..."
ssh "${SSH_OPTS[@]}" "${SSH_USER}@${SSH_HOST}" "mkdir -p '$REMOTE_DIST_DIR'"

scp -P "$SSH_PORT" ${SSH_KEY:+-i "$SSH_KEY"} \
    "$ARCHIVE_PATH" "${SSH_USER}@${SSH_HOST}:$REMOTE_DIST_DIR/"
echo "✅ Upload abgeschlossen."

# Optional: Checksumme prüfen (lokal vs. remote)
LOCAL_SUM="$(sha256sum "$ARCHIVE_PATH" | awk '{print $1}')"

REMOTE_FILE="$REMOTE_DIST_DIR/$ARCHIVE_NAME"
REMOTE_SUM="$(
  ssh -p "$SSH_PORT" ${SSH_KEY:+-i "$SSH_KEY"} "$SSH_USER@$SSH_HOST" bash -s -- "$REMOTE_FILE" << 'EOF'
set -euo pipefail
file="$1"

if command -v sha256sum >/dev/null 2>&1; then
  sha256sum "$file" | awk '{print $1}'
elif command -v shasum >/dev/null 2>&1; then
  shasum -a 256 "$file" | awk '{print $1}'
elif command -v openssl >/dev/null 2>&1; then
  # Ausgabe-Format: "SHA256(filename)= hash" oder "SHA256(file)= hash"
  openssl dgst -sha256 "$file" | awk '{print $NF}'
else
  echo "NO_TOOL"
fi
EOF
)"

if [[ "$REMOTE_SUM" == "NO_TOOL" ]]; then
  echo "⚠️  Auf dem Zielhost ist kein sha256-Tool verfügbar (sha256sum/shasum/openssl fehlen). Überspringe Integritäts-Check."
elif [[ "$LOCAL_SUM" != "$REMOTE_SUM" ]]; then
  echo "❌ Checksumme stimmt nicht überein! Lokal: $LOCAL_SUM  Remote: $REMOTE_SUM"
  exit 1
else
  echo "🔐 Checksumme verifiziert."
fi

# Remote: Entpacken & Cleanup
echo "🧰 Führe remote Deploy-Schritte aus ..."
ssh -p "$SSH_PORT" ${SSH_KEY:+-i "$SSH_KEY"} "$SSH_USER@$SSH_HOST" bash -s -- "$REMOTE_UNPACK_DIR" << 'REMOTE_CMDS'
set -euo pipefail
cd "$1"
[[ -x ./unpack.sh ]] || chmod +x ./unpack.sh
./unpack.sh
rm -rf var
REMOTE_CMDS
echo "✅ Remote Deploy-Schritte abgeschlossen."

echo "🎉 Fertig."
