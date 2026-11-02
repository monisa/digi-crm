#!/usr/bin/env bash
# Runs ON the Hostinger server (over SSH): first-time setup and every update.
#
#   BRANCH=main SITE_ROOT=... bash server-deploy.sh
#
# Layout:
#   ~/digi-crm                 git clone of this repository (code + .env, outside the website)
#   ~/digi-crm/api/.env        production settings (created once by the GitHub workflow, never committed)
#   $SITE_ROOT                 the crm subdomain folder (Angular files are uploaded here)
#   $SITE_ROOT/api             -> ~/digi-crm/api/public (symlink: Laravel's entry point)
set -euo pipefail

REPO_URL="${REPO_URL:-https://github.com/monisa/digi-crm.git}"
REPO_DIR="${REPO_DIR:-$HOME/digi-crm}"
SITE_ROOT="${SITE_ROOT:-domains/digifoxprosolutions.com/public_html/crm}"
case "$SITE_ROOT" in /*) ;; *) SITE_ROOT="$HOME/$SITE_ROOT" ;; esac
BRANCH="${BRANCH:-main}"
PHP="${PHP_BIN:-php}"
COMPOSER="${COMPOSER_BIN:-composer}"

echo "==> PHP: $($PHP -r 'echo PHP_VERSION;')"
$PHP -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' || {
  echo "!! PHP 8.3 or newer is needed. In hPanel: Advanced -> PHP Configuration -> 8.3 (or set PHP_BIN)." >&2; exit 1; }

if [ ! -d "$REPO_DIR/.git" ]; then
  echo "==> First run: cloning $REPO_URL ($BRANCH)"
  git clone --branch "$BRANCH" "$REPO_URL" "$REPO_DIR"
else
  echo "==> Updating code ($BRANCH)"
  cd "$REPO_DIR"
  git fetch origin "$BRANCH"
  git checkout -q "$BRANCH" 2>/dev/null || git checkout -q -b "$BRANCH" "origin/$BRANCH"
  git reset -q --hard "origin/$BRANCH"
fi

cd "$REPO_DIR/api"
if [ -f "$HOME/.digi-crm.env.new" ] && [ ! -f .env ]; then
  echo "==> Installing settings file (.env)"
  mv "$HOME/.digi-crm.env.new" .env
fi
rm -f "$HOME/.digi-crm.env.new"
chmod 600 .env 2>/dev/null || true
[ -f .env ] || { echo "!! $REPO_DIR/api/.env is missing." >&2; exit 1; }

echo "==> Installing PHP packages"
# Hostinger's PHP has proc_open turned off, which Composer needs to run Laravel's
# post-install scripts. Skip them in Composer and run the one we need directly.
$COMPOSER install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts
$PHP artisan package:discover --ansi

echo "==> Database"
$PHP artisan migrate --force
$PHP artisan db:seed --class=PermissionSeeder --force

if [ -n "${CRM_ADMIN_EMAIL:-}" ] && [ -n "${CRM_ADMIN_PASSWORD:-}" ]; then
  echo "==> First admin (only if none exists)"
  CRM_ADMIN_PASSWORD="$CRM_ADMIN_PASSWORD" $PHP artisan crm:create-admin --if-none --name="${CRM_ADMIN_NAME:-Admin}" --email="$CRM_ADMIN_EMAIL"
fi

echo "==> Caches"
$PHP artisan storage:link >/dev/null 2>&1 || true
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache

echo "==> Website folder"
mkdir -p "$SITE_ROOT"
if [ ! -L "$SITE_ROOT/api" ]; then
  rm -rf "$SITE_ROOT/api"
  ln -s "$REPO_DIR/api/public" "$SITE_ROOT/api"
fi
# Hostinger puts a placeholder page in new subdomains; the Angular app replaces it.
rm -f "$SITE_ROOT/default.php"

echo "==> Server part done."
