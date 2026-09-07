#!/usr/bin/env bash
#
# Deploy the current branch to this server.
#
#   cd /www/wwwroot/email.knbazaar.com && bash deploy.sh
#
# Safe to run repeatedly. It stops at the first failure rather than carrying on
# and leaving the site half-updated — which is the whole reason this is a script
# and not a list of commands in a document somebody pastes one line at a time.

set -euo pipefail

# ---------------------------------------------------------------- settings

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WEB_USER="${WEB_USER:-www}"
WORKER="${WORKER:-knsoftic-worker}"

cd "$APP_DIR"

# aaPanel keeps several PHP versions side by side and the system `php` is often
# an old one. Find the newest 8.x it has, unless PHP_BIN is set explicitly.
say() { printf '\n\033[1;34m==>\033[0m %s\n' "$1"; }
warn() { printf '\033[1;33m  ! %s\033[0m\n' "$1"; }
die() { printf '\n\033[1;31m  x %s\033[0m\n' "$1" >&2; exit 1; }

# Which PHP the WEBSITE runs, read from aaPanel's own vhost rather than guessed.
# Taking the newest installed version instead was wrong in a way that is easy to
# miss: composer would resolve dependencies against a PHP that serves no
# requests, and the extension check below would pass on the wrong binary.
if [ -z "${PHP_BIN:-}" ]; then
    VHOST="/www/server/panel/vhost/nginx/$(basename "$APP_DIR").conf"

    if [ -f "$VHOST" ]; then
        SITE_PHP="$(grep -oE 'enable-php-[0-9]+' "$VHOST" | head -1 | grep -oE '[0-9]+' || true)"

        if [ -n "${SITE_PHP:-}" ] && [ -x "/www/server/php/$SITE_PHP/bin/php" ]; then
            PHP_BIN="/www/server/php/$SITE_PHP/bin/php"
        fi
    fi
fi

if [ -z "${PHP_BIN:-}" ]; then
    for v in 84 83 82; do
        if [ -x "/www/server/php/$v/bin/php" ]; then
            PHP_BIN="/www/server/php/$v/bin/php"
            warn "Could not read the site's PHP version from its vhost; using $v."
            break
        fi
    done
fi
PHP_BIN="${PHP_BIN:-$(command -v php)}"

say "PHP: $PHP_BIN ($("$PHP_BIN" -r 'echo PHP_VERSION;'))"

# Checked here rather than left to composer, which reports a missing extension
# four times over as a lock-file problem and suggests `composer update` — which
# would be the wrong fix, and would quietly change the dependency set of a live
# server to work around a two-click panel setting.
MISSING=""
for ext in fileinfo mbstring openssl pdo_mysql tokenizer xml ctype curl zip gd; do
    "$PHP_BIN" -m | grep -qix "$ext" || MISSING="$MISSING $ext"
done

if [ -n "$MISSING" ]; then
    PHP_SHORT="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    warn "This PHP is missing:$MISSING"
    warn "aaPanel > App Store > PHP $PHP_SHORT > Settings > Install extensions"
    die "Install them and run this again. Do not run 'composer update' to get past it."
fi

# --------------------------------------------------------------- guardrails

if [ ! -f .env ]; then
    echo "No .env here. This looks like the wrong directory, or a first install." >&2
    echo "For a first install follow DEPLOY-AAPANEL.md instead." >&2
    exit 1
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    echo "APP_KEY is not set in .env. Stopping: without it nothing decrypts." >&2
    exit 1
fi

# A reminder rather than a check, because only you know whether it was taken.
warn "Take a database backup before continuing if you have not already."

# ------------------------------------------------------------------ deploy

say "Putting the site into maintenance mode"
"$PHP_BIN" artisan down --render="errors::503" --retry=60 || true

restore() {
    say "Bringing the site back up"
    "$PHP_BIN" artisan up || true
}
trap restore EXIT

say "Fetching the latest code"
if [ -d .git ]; then
    git pull --ff-only
else
    warn "Not a git checkout — upload the new files, then run this again."
fi

say "Installing PHP dependencies"
"$PHP_BIN" "$(command -v composer || echo /usr/local/bin/composer)" \
    install --no-dev --optimize-autoloader --no-interaction

say "Building the front end"
if command -v npm >/dev/null 2>&1; then
    npm ci --silent
    npm run build
else
    warn "npm not found. Build public/build on your own machine and upload it —"
    warn "without it every page loads unstyled. See DEPLOY-AAPANEL.md section 6."
fi

if [ ! -f public/build/manifest.json ]; then
    echo "public/build/manifest.json is missing. The site will not render correctly." >&2
    exit 1
fi

say "Running migrations"
"$PHP_BIN" artisan migrate --force

say "Rebuilding caches"
# Cleared BEFORE re-caching, and both are necessary: new routes (robots.txt,
# sitemap.xml, /search, /activity) and new views (the error pages) are invisible
# until the old caches are dropped.
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache

say "Linking storage"
# Idempotent, and it is what makes the branding logo and user avatars resolve.
"$PHP_BIN" artisan storage:link 2>/dev/null || true

say "Fixing ownership"
chown -R "$WEB_USER:$WEB_USER" "$APP_DIR"
chmod -R 775 storage bootstrap/cache

say "Restarting the queue worker"
# Not optional: a running worker holds the old code in memory until it is
# replaced, so without this it keeps executing the version you just replaced.
if command -v supervisorctl >/dev/null 2>&1; then
    supervisorctl restart "$WORKER" || warn "Could not restart $WORKER — restart it from aaPanel."
else
    warn "supervisorctl not found — restart the queue worker from aaPanel > Supervisor."
fi

# ------------------------------------------------------------------ checks

say "Checking the result"

"$PHP_BIN" artisan schedule:list | sed 's/^/    /' || true

APP_URL="$(grep -E '^APP_URL=' .env | cut -d= -f2- | tr -d '"' | tr -d "'")"
printf '\n    APP_URL is %s\n' "$APP_URL"

case "$APP_URL" in
    *localhost*|*127.0.0.1*|"")
        warn "APP_URL looks wrong. Every unsubscribe and tracking link is signed"
        warn "from it, so campaigns would go out with links nobody can open."
        ;;
esac

say "Done"
