#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
DEST="${ROOT}/../reminder-note-backups"
mkdir -p "$DEST"

cp -f "${ROOT}/data/app.db" "${DEST}/app-${STAMP}.db" 2>/dev/null || echo "WARN: data/app.db not found"

if [[ -d "${ROOT}/public/uploads" ]]; then
    tar czf "${DEST}/uploads-${STAMP}.tar.gz" -C "${ROOT}/public" uploads
fi

find "$DEST" -name 'app-*.db' -mtime +14 -delete 2>/dev/null || true
find "$DEST" -name 'uploads-*.tar.gz' -mtime +14 -delete 2>/dev/null || true
echo "Backed up to $DEST"
