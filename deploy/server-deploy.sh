#!/usr/bin/env bash
# Runs ON the Hostinger server (over SSH) to deploy the latest code.
#
#   bash ~/digi-crm/deploy/server-deploy.sh
#
# Layout it expects / creates:
#   ~/digi-crm                         git clone of this repository
#   ~/digi-crm/api/.env                production settings (create once, never commit)
#   $SITE_ROOT                         the crm subdomain's folder (Angular files go here)
#   $SITE_ROOT/api -> ~/digi-crm/api/public   (symlink: Laravel's public entry point)
set -euo pipefail

REPO_DIR="${REPO_DIR:-$HOME/digi-crm}"
SITE_ROOT="${SITE_ROOT:-$HOME/domains/digifoxprosolutions.com/public_html/crm}"
BRANCH="${BRANCH:-main}"

echo "==> Updating code ($BRANCH)"
cd "$REPO_DIR"
git fetch origin "$BRANCH"
git checkout "$BRANCH"
git reset --hard "origin/$BRANCH"

echo "==> Installing PHP dependencies"
cd "$REPO_DIR/api"
composer install --no-dev --optimize-autoloader --no-interaction

if [ ! -f .env ]; then
  echo "!! $REPO_DIR/api/.env is missing. Copy .env.example to .env, fill it in, run 'php artisan key:generate', then re-run." >&2
  exit 1
fi

echo "==> Running database migrations"
php artisan migrate --force

echo "==> Caching config and routes"
php artisan config:cache
php artisan route:cache
php artisan storage:link || true

echo "==> Linking API into the website folder"
mkdir -p "$SITE_ROOT"
if [ ! -e "$SITE_ROOT/api" ]; then
  ln -s "$REPO_DIR/api/public" "$SITE_ROOT/api"
fi

echo "==> Done. Health check: https://crm.digifoxprosolutions.com/api/v1/health"
