#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PRIVATE_CONFIG="${MEDIAHUB_DEPLOY_CONFIG:-$ROOT_DIR/.mediahub-deploy.env}"

if [[ -f "$PRIVATE_CONFIG" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "$PRIVATE_CONFIG"
  set +a
fi

log() { printf '[mediahub deploy] %s\n' "$*"; }
fail() { printf '[mediahub deploy] ERROR: %s\n' "$*" >&2; exit 1; }
quote() { printf '%q' "$1"; }

MODE="deploy"
case "${1:-}" in
  "") ;;
  --check) MODE="check" ;;
  -h|--help)
    cat <<'USAGE'
Usage: ./deploy-mediahub.sh [--check]

Loads private infrastructure values from .mediahub-deploy.env by default.
The committed .mediahub-deploy.env.example documents the required variables.
USAGE
    exit 0
    ;;
  *) fail "Unknown argument: $1" ;;
esac

required=(
  MEDIAHUB_SSH_TARGET
  MEDIAHUB_SERVER_USER
  MEDIAHUB_SERVER_APP_DIR
  MEDIAHUB_SERVER_BACKUP_ROOT
  MEDIAHUB_APACHE_CONF
  MEDIAHUB_LIVE_URL
)
for variable in "${required[@]}"; do
  [[ -n "${!variable:-}" ]] || fail "$variable is required; configure $PRIVATE_CONFIG"
done

BRANCH="${MEDIAHUB_BRANCH:-main}"
REMOTE="${MEDIAHUB_REMOTE:-origin}"
SERVER_DB_PATH="${MEDIAHUB_SERVER_DB_PATH:-$MEDIAHUB_SERVER_APP_DIR/backend/database/database.sqlite}"
FRONTEND_PUBLIC_DIR="${MEDIAHUB_FRONTEND_PUBLIC_DIR:-$MEDIAHUB_SERVER_APP_DIR/backend/public}"

SSH_ARGS=(-o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new)
if [[ -n "${MEDIAHUB_SSH_IDENTITY:-}" ]]; then
  [[ -r "$MEDIAHUB_SSH_IDENTITY" ]] || fail "Configured SSH identity is not readable"
  SSH_ARGS=(-i "$MEDIAHUB_SSH_IDENTITY" "${SSH_ARGS[@]}")
fi

verify_local_state() {
  local branch head remote_head
  for tool in git ssh curl; do command -v "$tool" >/dev/null || fail "Missing local tool: $tool"; done

  branch="$(git -C "$ROOT_DIR" rev-parse --abbrev-ref HEAD)"
  [[ "$branch" == "$BRANCH" ]] || fail "Expected branch $BRANCH, found $branch"
  [[ -z "$(git -C "$ROOT_DIR" status --porcelain)" ]] || fail "Working tree must be clean"

  git -C "$ROOT_DIR" fetch --quiet "$REMOTE" "$BRANCH"
  head="$(git -C "$ROOT_DIR" rev-parse HEAD)"
  remote_head="$(git -C "$ROOT_DIR" rev-parse "$REMOTE/$BRANCH")"
  [[ "$head" == "$remote_head" ]] || fail "Local HEAD must exactly match $REMOTE/$BRANCH"
  log "Local branch, clean tree, and pushed commit verified"
}

verify_ssh() {
  ssh "${SSH_ARGS[@]}" "$MEDIAHUB_SSH_TARGET" 'printf ssh-ok' >/dev/null || fail "SSH access failed"
  log "SSH access verified"
}

verify_live() {
  local headers root_status private_status status_status header
  headers="$(mktemp)"
  root_status="$(curl -sS -D "$headers" -o /dev/null -w '%{http_code}' "$MEDIAHUB_LIVE_URL/" || true)"
  [[ "$root_status" == "200" ]] || fail "Expected live root status 200, got $root_status"
  ! grep -qi '^www-authenticate:' "$headers" || fail "Unexpected HTTP Basic Auth challenge"
  grep -qi '^x-robots-tag:.*noindex' "$headers" || fail "Missing noindex protection"
  for header in x-content-type-options x-frame-options referrer-policy permissions-policy; do
    grep -qi "^${header}:" "$headers" || fail "Missing live security header: $header"
  done
  rm -f "$headers"

  status_status="$(curl -sS -H 'Accept: application/json' -o /dev/null -w '%{http_code}' "$MEDIAHUB_LIVE_URL/api/v1/status" || true)"
  private_status="$(curl -sS -H 'Accept: application/json' -o /dev/null -w '%{http_code}' "$MEDIAHUB_LIVE_URL/api/v1/me" || true)"
  [[ "$status_status" == "200" ]] || fail "Expected status endpoint 200, got $status_status"
  [[ "$private_status" == "401" ]] || fail "Expected unauthenticated private API status 401, got $private_status"
  log "Live readiness, Laravel authentication, noindex, and security headers verified"
}

verify_remote_state() {
  local command
  command="SERVER_APP_DIR=$(quote "$MEDIAHUB_SERVER_APP_DIR")"
  command+=" SERVER_USER=$(quote "$MEDIAHUB_SERVER_USER")"
  command+=" DB_PATH=$(quote "$SERVER_DB_PATH") BRANCH=$(quote "$BRANCH") bash -s"
  ssh "${SSH_ARGS[@]}" "$MEDIAHUB_SSH_TARGET" "$command" <<'REMOTE_CHECK'
set -Eeuo pipefail
for tool in git runuser php composer npm sqlite3 apachectl systemctl; do
  command -v "$tool" >/dev/null || { echo "Missing server tool: $tool" >&2; exit 1; }
done
[[ -d "$SERVER_APP_DIR/.git" ]] || { echo 'Server checkout missing' >&2; exit 1; }
site_home="$(getent passwd "$SERVER_USER" | cut -d: -f6)"
[[ -n "$site_home" ]] || { echo 'Server user does not exist' >&2; exit 1; }
run_as_site() { runuser -u "$SERVER_USER" -- env HOME="$site_home" TMPDIR=/tmp PATH=/usr/local/bin:/usr/bin:/bin "$@"; }
[[ "$(run_as_site git -C "$SERVER_APP_DIR" rev-parse --abbrev-ref HEAD)" == "$BRANCH" ]] || { echo 'Server branch mismatch' >&2; exit 1; }
[[ -z "$(run_as_site git -C "$SERVER_APP_DIR" status --porcelain)" ]] || { echo 'Server checkout is dirty' >&2; exit 1; }
run_as_site test -w "$SERVER_APP_DIR" || { echo 'Server checkout is not writable by site user' >&2; exit 1; }
run_as_site test -w "$SERVER_APP_DIR/.git/objects" || { echo 'Git objects are not writable by site user' >&2; exit 1; }
[[ -s "$SERVER_APP_DIR/backend/.env" ]] || { echo 'Laravel environment file missing' >&2; exit 1; }
run_as_site grep -Eq '^APP_KEY=(base64:)?[^[:space:]]+' "$SERVER_APP_DIR/backend/.env" || { echo 'Laravel APP_KEY missing' >&2; exit 1; }
if [[ -f "$DB_PATH" ]]; then
  [[ "$(sqlite3 "$DB_PATH" 'PRAGMA quick_check;')" == 'ok' ]] || { echo 'SQLite quick check failed' >&2; exit 1; }
fi
for path in "$SERVER_APP_DIR/.git" "$SERVER_APP_DIR/node_modules" "$SERVER_APP_DIR/backend/vendor"; do
  [[ ! -e "$path" ]] || ! find "$path" ! -user "$SERVER_USER" -print -quit | grep -q . || {
    echo "Build path has files not owned by site user: ${path#$SERVER_APP_DIR/}" >&2
    exit 1
  }
done
REMOTE_CHECK
  log "Server checkout verified"
}

run_remote_deploy() {
  local command
  command="SERVER_USER=$(quote "$MEDIAHUB_SERVER_USER")"
  command+=" SERVER_APP_DIR=$(quote "$MEDIAHUB_SERVER_APP_DIR")"
  command+=" BACKUP_ROOT=$(quote "$MEDIAHUB_SERVER_BACKUP_ROOT")"
  command+=" DB_PATH=$(quote "$SERVER_DB_PATH")"
  command+=" APACHE_CONF=$(quote "$MEDIAHUB_APACHE_CONF")"
  command+=" PUBLIC_DIR=$(quote "$FRONTEND_PUBLIC_DIR")"
  command+=" BRANCH=$(quote "$BRANCH") REMOTE=$(quote "$REMOTE") bash -s"

  ssh "${SSH_ARGS[@]}" "$MEDIAHUB_SSH_TARGET" "$command" <<'REMOTE_DEPLOY'
set -Eeuo pipefail
IFS=$'\n\t'

log() { printf '[mediahub remote] %s\n' "$*"; }
fail() { printf '[mediahub remote] ERROR: %s\n' "$*" >&2; exit 1; }

site_home="$(getent passwd "$SERVER_USER" | cut -d: -f6)"
[[ -n "$site_home" ]] || fail "Server user does not exist"
run_as_site() { runuser -u "$SERVER_USER" -- env HOME="$site_home" TMPDIR=/tmp PATH=/usr/local/bin:/usr/bin:/bin "$@"; }
run_in_dir() {
  local directory="$1"
  shift
  run_as_site bash -c 'cd "$1"; shift; exec "$@"' _ "$directory" "$@"
}

sync_frontend() {
  [[ -f "$SERVER_APP_DIR/dist/index.html" ]] || fail "React index is missing"
  [[ -d "$SERVER_APP_DIR/dist/assets" ]] || fail "React assets are missing"
  [[ -f "$PUBLIC_DIR/index.php" ]] || fail "Laravel index.php is missing"
  [[ -f "$PUBLIC_DIR/.htaccess" ]] || fail "Laravel .htaccess is missing"
  case "$PUBLIC_DIR" in ""|/|"$SERVER_APP_DIR"|"$SERVER_APP_DIR/backend") fail "Unsafe public directory" ;; esac

  run_as_site mkdir -p "$PUBLIC_DIR/assets"
  run_as_site cp "$SERVER_APP_DIR/dist/index.html" "$PUBLIC_DIR/index.html"
  run_as_site cp -a "$SERVER_APP_DIR/dist/assets/." "$PUBLIC_DIR/assets/"
  for asset in favicon.svg mediahub-pinned-tab.svg site.webmanifest; do
    [[ ! -f "$SERVER_APP_DIR/dist/$asset" ]] || run_as_site cp "$SERVER_APP_DIR/dist/$asset" "$PUBLIC_DIR/$asset"
  done

  [[ -f "$PUBLIC_DIR/index.php" && -f "$PUBLIC_DIR/.htaccess" && -f "$PUBLIC_DIR/index.html" ]] || fail "Frontend sync damaged Laravel public files"
  find "$PUBLIC_DIR/assets" -maxdepth 1 -type f -name '*.js' -print -quit | grep -q . || fail "Built JavaScript missing"
  find "$PUBLIC_DIR/assets" -maxdepth 1 -type f -name '*.css' -print -quit | grep -q . || fail "Built CSS missing"
}

timestamp="$(date +%Y%m%d%H%M%S)"
backup="$BACKUP_ROOT/$timestamp"
install -d -m 700 "$backup"
git -C "$SERVER_APP_DIR" rev-parse HEAD > "$backup/commit-before.txt"
[[ ! -f "$APACHE_CONF" ]] || cp "$APACHE_CONF" "$backup/apache.conf"
if [[ -f "$DB_PATH" ]]; then
  command -v sqlite3 >/dev/null || fail "sqlite3 is required for a consistent backup"
  sqlite3 "$DB_PATH" ".backup '$backup/database.sqlite'"
  chmod 600 "$backup/database.sqlite"
fi
(cd "$backup" && find . -maxdepth 1 -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS)
log "Backup created: $backup"

run_as_site git -C "$SERVER_APP_DIR" fetch "$REMOTE" "$BRANCH"
run_as_site git -C "$SERVER_APP_DIR" checkout "$BRANCH"
run_as_site git -C "$SERVER_APP_DIR" merge --ff-only "$REMOTE/$BRANCH"

run_in_dir "$SERVER_APP_DIR/backend" composer install --no-dev --optimize-autoloader --no-interaction
run_in_dir "$SERVER_APP_DIR/backend" php artisan migrate --force --no-interaction
run_in_dir "$SERVER_APP_DIR/backend" php artisan config:cache
run_in_dir "$SERVER_APP_DIR/backend" php artisan route:cache
run_in_dir "$SERVER_APP_DIR/backend" php artisan view:cache
if [[ ! -e "$PUBLIC_DIR/storage" ]]; then run_in_dir "$SERVER_APP_DIR/backend" php artisan storage:link; fi
if run_in_dir "$SERVER_APP_DIR/backend" php artisan list --raw | grep -q '^filament:assets$'; then
  run_in_dir "$SERVER_APP_DIR/backend" php artisan filament:assets
fi

run_in_dir "$SERVER_APP_DIR" npm ci
run_in_dir "$SERVER_APP_DIR" npm run build -- --emptyOutDir
sync_frontend

run_in_dir "$SERVER_APP_DIR/backend" php artisan route:list --path=api/v1/status --no-interaction | grep -q 'api/v1/status' || fail "Status route missing"
apachectl configtest
systemctl reload apache2

[[ -z "$(run_as_site git -C "$SERVER_APP_DIR" status --porcelain)" ]] || fail "Server checkout became dirty"
printf 'BACKUP_PATH=%s\n' "$backup"
printf 'DEPLOYED_COMMIT=%s\n' "$(git -C "$SERVER_APP_DIR" rev-parse HEAD)"
REMOTE_DEPLOY
}

verify_local_state
verify_ssh
verify_remote_state
verify_live
if [[ "$MODE" == "check" ]]; then
  log "Preflight complete; no deployment performed"
  exit 0
fi
run_remote_deploy
verify_live
log "Deployment completed"
