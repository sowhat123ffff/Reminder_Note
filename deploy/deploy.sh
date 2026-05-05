#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
git pull --ff-only || true
composer install --no-dev --optimize-autoloader
npm ci --omit=optional 2>/dev/null || npm ci
npm run build
sudo systemctl reload php8.3-fpm 2>/dev/null || true
sudo nginx -t && sudo systemctl reload nginx 2>/dev/null || true
echo "Deploy complete: $ROOT"
