#!/usr/bin/env bash
# Runs on the configured host as the application owner, without root services.
set -Eeuo pipefail
umask 077
release="$(cd "$(dirname "$0")" && pwd)"
mode=deploy
case "${1:-}" in "") ;; --check) mode=check ;; *) printf 'Usage: bash deploy.sh [--check]\n' >&2; exit 1 ;; esac
fail() { printf '[mediahub release] %s\n' "$*" >&2; exit 1; }
backup=""
maintenance=0
trap 'code=$?; if (( code != 0 )); then printf "Release stopped. Recovery backup: %s; maintenance enabled by this run: %s\n" "${backup:-not created}" "$maintenance" >&2; fi' EXIT
cd "$release"
sha256sum --check SHA256SUMS
# Contains only deployment topology, generated with shell quoting; no secrets.
source "$release/release.env"
[[ "$(hostname)" == "$MEDIAHUB_SERVER_HOSTNAME" ]] || fail 'Unexpected server'
[[ "$(id -un)" == "$MEDIAHUB_SERVER_USER" ]] || fail 'Run as the configured application owner'
target="$(cat COMMIT)"
[[ "$target" =~ ^[a-f0-9]{40}$ ]] || fail 'Invalid release commit'
app="$MEDIAHUB_SERVER_APP_DIR"
public="$app/backend/public"
db="$app/backend/database/database.sqlite"
for tool in git php composer python3 tar curl sha256sum flock; do command -v "$tool" >/dev/null || fail "Missing tool: $tool"; done
cd "$app"
exec 9>"$app/.git/mediahub-deploy.lock"
flock -n 9 || fail 'Another deployment is running'
[[ "$(git branch --show-current)" == "$MEDIAHUB_BRANCH" ]] || fail 'Production branch mismatch'
[[ -z "$(git status --porcelain)" ]] || fail 'Production checkout has local changes; preserve and review them'
[[ -s backend/.env && -f "$db" ]] || fail 'Production environment/database missing'
[[ ! -e backend/storage/framework/down ]] || fail 'Application is already in maintenance mode'
for path in .git/objects backend/vendor backend/public backend/storage backend/bootstrap/cache; do [[ -w "$path" ]] || fail "Application owner cannot write $path"; done
php -r 'exit(extension_loaded("pdo_sqlite") && extension_loaded("gd") ? 0 : 1);' || fail 'Required PHP extensions missing'
php -r 'require "backend/vendor/autoload.php"; $app = require "backend/bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (config("database.default") !== "sqlite" || realpath(config("database.connections.sqlite.database")) !== realpath("backend/database/database.sqlite") || !config("app.key")) { fwrite(STDERR, "Runtime database/key preflight failed\n"); exit(1); }'
[[ "$(df --output=avail -k "$app" | tail -1 | tr -d ' ')" -gt 2097152 ]] || fail 'Less than 2 GiB free'
check_http() {
  local actual
  actual="$(curl --noproxy '*' --silent --show-error --max-time 20 -H 'Accept: application/json' -o /dev/null -w '%{http_code}' "$MEDIAHUB_LIVE_URL$1")"
  [[ "$actual" == "$2" ]] || fail "HTTP $1 returned $actual; expected $2"
}
check_http / 200
check_http /api/v1/status 200
check_http /api/v1/auth/session 200
check_http /api/v1/me 401
before="$(git rev-parse HEAD)"
git fetch --no-tags "$release/source.bundle" HEAD
[[ "$(git rev-parse FETCH_HEAD)" == "$target" ]] || fail 'Bundle revision mismatch'
git merge-base --is-ancestor "$before" "$target" || fail 'Release would not fast-forward production'
migrations="$(python3 "$release/migration-plan.py" "$before" "$target")" || fail 'Migration plan verification failed'
umask 022
frontend="$(mktemp -d)"
tar -xzf "$release/frontend.tar.gz" --no-same-owner -C "$frontend"
python3 "$release/release-assets.py" validate "$frontend"
umask 077
if [[ "$mode" == check ]]; then printf 'Production preflight passed. No application files changed.\n'; exit 0; fi
if [[ "$before" == "$target" ]]; then
  python3 "$release/release-assets.py" verify "$frontend" "$MEDIAHUB_LIVE_URL" /
  printf 'Production already serves verified commit %s.\n' "$target"; exit 0
fi
backup="$MEDIAHUB_SERVER_BACKUP_ROOT/$(date -u +%Y%m%dT%H%M%SZ)-${target:0:7}"
mkdir -p -m 700 "$backup"
printf '%s\n' "$before" > "$backup/commit-before.txt"
printf '%s\n' "$target" > "$backup/commit-after.txt"
sha256sum backend/.env > "$backup/environment.sha256"
git bundle create "$backup/source-before.bundle" HEAD
tar -czf "$backup/runtime-before.tar.gz" --exclude=backend/public/storage backend/vendor backend/public
if [[ -f backend/storage/app/private/webpush/keys.json ]]; then
  cp backend/storage/app/private/webpush/keys.json "$backup/webpush-keys.json"
  chmod 600 "$backup/webpush-keys.json"
fi
python3 - "$db" "$backup/database.sqlite" <<'PY'
import sqlite3, sys
from pathlib import Path
source = sqlite3.connect(Path(sys.argv[1]).as_uri() + '?mode=ro', uri=True, timeout=10)
assert source.execute('pragma quick_check').fetchone()[0] == 'ok', 'Live database integrity failed'
dest = sqlite3.connect(sys.argv[2])
source.backup(dest)
assert dest.execute('pragma quick_check').fetchone()[0] == 'ok', 'Backup database integrity failed'
dest.close()
source.close()
PY
(cd "$backup" && sha256sum commit-before.txt commit-after.txt source-before.bundle runtime-before.tar.gz database.sqlite > SHA256SUMS && sha256sum --check SHA256SUMS)
if [[ -f "$backup/webpush-keys.json" ]]; then
  (cd "$backup" && sha256sum webpush-keys.json >> SHA256SUMS && sha256sum --check SHA256SUMS)
fi
printf 'Verified recovery backup: %s\n' "$backup"
php backend/artisan down --retry=60
maintenance=1
git merge --ff-only "$target"
umask 022
(cd backend && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction)
php backend/artisan filament:assets
umask 077
php backend/artisan config:clear
if [[ -n "$migrations" ]]; then
  migration_args=()
  while IFS= read -r migration; do migration_args+=("--path=${migration#backend/}"); done <<< "$migrations"
  (cd backend && php artisan migrate --force "${migration_args[@]}")
  php backend/artisan mediahub:configure-push
fi
php backend/artisan config:cache
php backend/artisan route:cache
php backend/artisan view:cache
python3 "$release/release-assets.py" stage "$frontend" "$public" "$target"
python3 "$release/release-assets.py" verify "$frontend" "$MEDIAHUB_LIVE_URL" "/assets/mediahub-release-check-$target.html"
sha256sum --check "$backup/environment.sha256"
[[ "$(git rev-parse HEAD)" == "$target" && -z "$(git status --porcelain)" ]] || fail 'Checkout differs from the release'
python3 "$release/release-assets.py" publish "$public" "$target"
php backend/artisan up
maintenance=0
check_http / 200
check_http /discover 200
check_http /api/v1/status 200
check_http /api/v1/auth/session 200
check_http /api/v1/me 401
python3 "$release/release-assets.py" verify "$frontend" "$MEDIAHUB_LIVE_URL" /
printf 'DEPLOYED_COMMIT=%s\nBACKUP_PATH=%s\n' "$target" "$backup"
