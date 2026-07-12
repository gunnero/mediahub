#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

usage() {
  cat <<'USAGE'
Usage:
  ./deploy-mediahub.sh [--check]

Deploys MediaHub staging from GitHub to web01. The script is safe to keep in
Git: all Laravel app credentials and API keys must be supplied through the
environment and are never printed.

Options:
  --check   Verify local git, SSH access, and live protection without deploying.

Useful environment variables:
  MEDIAHUB_BRANCH=main
  MEDIAHUB_REMOTE=origin
  MEDIAHUB_SSH_HOST=web01
  MEDIAHUB_SSH_USER=root
  MEDIAHUB_SSH_IDENTITY=$HOME/.ssh/123url_ed25519
  MEDIAHUB_SERVER_SITE_ROOT=/home/razbudise/ccc.razbudise.mk
  MEDIAHUB_FRONTEND_PUBLIC_DIR=/home/razbudise/ccc.razbudise.mk/app/backend/public
  MEDIAHUB_LIVE_URL=https://ccc.razbudise.mk

Optional smoke credentials:
  MEDIAHUB_APP_EMAIL=...
  MEDIAHUB_APP_PASSWORD=...
USAGE
}

log() {
  printf '[mediahub deploy] %s\n' "$*"
}

fail() {
  printf '[mediahub deploy] ERROR: %s\n' "$*" >&2
  exit 1
}

quote() {
  printf '%q' "$1"
}

MODE="deploy"
for arg in "$@"; do
  case "$arg" in
    --check)
      MODE="check"
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      fail "Unknown argument: $arg"
      ;;
  esac
done

BRANCH="${MEDIAHUB_BRANCH:-main}"
REMOTE="${MEDIAHUB_REMOTE:-origin}"
SSH_HOST="${MEDIAHUB_SSH_HOST:-web01}"
SSH_USER="${MEDIAHUB_SSH_USER:-root}"
SSH_IDENTITY="${MEDIAHUB_SSH_IDENTITY:-$HOME/.ssh/123url_ed25519}"
SSH_TARGET="${MEDIAHUB_SSH_TARGET:-${SSH_USER}@${SSH_HOST}}"
SERVER_SITE_ROOT="${MEDIAHUB_SERVER_SITE_ROOT:-/home/razbudise/ccc.razbudise.mk}"
SERVER_APP_DIR="${MEDIAHUB_SERVER_APP_DIR:-$SERVER_SITE_ROOT/app}"
SERVER_BACKUP_ROOT="${MEDIAHUB_SERVER_BACKUP_ROOT:-$SERVER_SITE_ROOT/backups}"
SERVER_DB_PATH="${MEDIAHUB_SERVER_DB_PATH:-$SERVER_APP_DIR/backend/database/database.sqlite}"
APACHE_CONF="${MEDIAHUB_APACHE_CONF:-/etc/apache2/sites-available/ccc.razbudise.mk.conf}"
LIVE_URL="${MEDIAHUB_LIVE_URL:-https://ccc.razbudise.mk}"
FRONTEND_PUBLIC_DIR="${MEDIAHUB_FRONTEND_PUBLIC_DIR:-$SERVER_APP_DIR/backend/public}"
PHP_FPM_SERVICE="${MEDIAHUB_PHP_FPM_SERVICE:-}"
RUN_APACHE_RELOAD="${MEDIAHUB_RELOAD_APACHE:-true}"

SSH_ARGS=(
  -i "$SSH_IDENTITY"
  -o BatchMode=yes
  -o IdentitiesOnly=yes
  -o StrictHostKeyChecking=accept-new
)

FORBIDDEN_PAYLOAD_KEYS=(
  stream_url
  playbackUrl
  provider_url
  playlist_url
  password
  api_key
  token
  secret
  credential
)

require_local_tools() {
  local missing=()
  for tool in git ssh curl; do
    if ! command -v "$tool" >/dev/null 2>&1; then
      missing+=("$tool")
    fi
  done

  if ((${#missing[@]} > 0)); then
    fail "Missing local tools: ${missing[*]}"
  fi
}

verify_git_state() {
  local current_branch dirty
  current_branch="$(git rev-parse --abbrev-ref HEAD)"
  if [[ "$current_branch" != "$BRANCH" ]]; then
    fail "Expected branch $BRANCH but found $current_branch"
  fi

  dirty="$(git status --porcelain)"
  if [[ -n "$dirty" ]]; then
    git status --short
    fail "Working tree must be clean before deployment"
  fi

  log "Git branch and clean tree verified"
}

verify_ssh_access() {
  if [[ ! -r "$SSH_IDENTITY" ]]; then
    fail "SSH identity is not readable: $SSH_IDENTITY"
  fi

  log "Checking SSH access to $SSH_TARGET with $SSH_IDENTITY"
  if ! ssh "${SSH_ARGS[@]}" "$SSH_TARGET" 'printf "ssh-ok:%s\n" "$(hostname)"' >/dev/null; then
    fail "SSH failed. Add the key to macOS Keychain or install the public key in authorized_keys; see docs/infrastructure/SSH_SETUP.md"
  fi

  log "SSH access verified"
}

verify_live_protection() {
  local headers status private_status
  headers="$(mktemp)"
  status="$(curl -sS -o /dev/null -D "$headers" -w '%{http_code}' "$LIVE_URL/" || true)"

  if [[ "$status" != "200" ]]; then
    rm -f "$headers"
    fail "Expected the public MediaHub login page to return 200, got $status"
  fi

  if grep -iq '^www-authenticate:' "$headers"; then
    rm -f "$headers"
    fail "Unexpected Apache Basic Auth challenge on $LIVE_URL/"
  fi

  if ! grep -iq '^x-robots-tag:.*noindex' "$headers"; then
    cat "$headers" >&2
    rm -f "$headers"
    fail "Expected X-Robots-Tag noindex header on staging"
  fi

  private_status="$(curl -sS -H 'Accept: application/json' -o /dev/null -w '%{http_code}' "$LIVE_URL/api/v1/me" || true)"
  rm -f "$headers"

  if [[ "$private_status" != "401" ]]; then
    fail "Expected unauthenticated Laravel /api/v1/me to return 401, got $private_status"
  fi

  log "Public login, Laravel authentication, and X-Robots-Tag verified"
}

run_remote_deploy() {
  local remote_command
  remote_command="MEDIAHUB_BRANCH=$(quote "$BRANCH")"
  remote_command+=" MEDIAHUB_REMOTE=$(quote "$REMOTE")"
  remote_command+=" SERVER_SITE_ROOT=$(quote "$SERVER_SITE_ROOT")"
  remote_command+=" SERVER_APP_DIR=$(quote "$SERVER_APP_DIR")"
  remote_command+=" SERVER_BACKUP_ROOT=$(quote "$SERVER_BACKUP_ROOT")"
  remote_command+=" SERVER_DB_PATH=$(quote "$SERVER_DB_PATH")"
  remote_command+=" APACHE_CONF=$(quote "$APACHE_CONF")"
  remote_command+=" FRONTEND_PUBLIC_DIR=$(quote "$FRONTEND_PUBLIC_DIR")"
  remote_command+=" PHP_FPM_SERVICE=$(quote "$PHP_FPM_SERVICE")"
  remote_command+=" RUN_APACHE_RELOAD=$(quote "$RUN_APACHE_RELOAD")"
  remote_command+=" bash -s"

  ssh "${SSH_ARGS[@]}" "$SSH_TARGET" "$remote_command" <<'REMOTE_DEPLOY'
set -Eeuo pipefail
IFS=$'\n\t'

log() {
  printf '[mediahub remote] %s\n' "$*"
}

fail() {
  printf '[mediahub remote] ERROR: %s\n' "$*" >&2
  exit 1
}

sync_frontend_build() {
  local js_count css_count

  [[ -f "$SERVER_APP_DIR/dist/index.html" ]] || fail "React build is missing $SERVER_APP_DIR/dist/index.html"
  [[ -d "$SERVER_APP_DIR/dist/assets" ]] || fail "React build is missing $SERVER_APP_DIR/dist/assets"

  case "$FRONTEND_PUBLIC_DIR" in
    ""|"/"|"$SERVER_APP_DIR"|"$SERVER_SITE_ROOT"|"$SERVER_APP_DIR/backend")
      fail "Refusing unsafe frontend sync target: $FRONTEND_PUBLIC_DIR"
      ;;
  esac

  if [[ "$FRONTEND_PUBLIC_DIR" == "$SERVER_APP_DIR/dist" ]]; then
    log "Frontend build remains in $SERVER_APP_DIR/dist"
    return 0
  fi

  log "Syncing React build into $FRONTEND_PUBLIC_DIR"
  mkdir -p "$FRONTEND_PUBLIC_DIR/assets"

  [[ -f "$FRONTEND_PUBLIC_DIR/index.php" ]] || fail "Laravel public index.php is missing before frontend sync"
  [[ -f "$FRONTEND_PUBLIC_DIR/.htaccess" ]] || fail "Laravel public .htaccess is missing before frontend sync"

  cp "$SERVER_APP_DIR/dist/index.html" "$FRONTEND_PUBLIC_DIR/index.html"
  cp -a "$SERVER_APP_DIR/dist/assets/." "$FRONTEND_PUBLIC_DIR/assets/"
  for public_asset in favicon.svg mediahub-pinned-tab.svg site.webmanifest; do
    if [[ -f "$SERVER_APP_DIR/dist/$public_asset" ]]; then
      cp "$SERVER_APP_DIR/dist/$public_asset" "$FRONTEND_PUBLIC_DIR/$public_asset"
    fi
  done

  [[ -f "$FRONTEND_PUBLIC_DIR/index.php" ]] || fail "Laravel public index.php is missing after frontend sync"
  [[ -f "$FRONTEND_PUBLIC_DIR/.htaccess" ]] || fail "Laravel public .htaccess is missing after frontend sync"
  [[ -f "$FRONTEND_PUBLIC_DIR/index.html" ]] || fail "React index.html was not synced"

  js_count="$(find "$FRONTEND_PUBLIC_DIR/assets" -maxdepth 1 -type f -name '*.js' | wc -l | tr -d '[:space:]')"
  css_count="$(find "$FRONTEND_PUBLIC_DIR/assets" -maxdepth 1 -type f -name '*.css' | wc -l | tr -d '[:space:]')"

  [[ "$js_count" -gt 0 ]] || fail "No built JavaScript assets found in $FRONTEND_PUBLIC_DIR/assets"
  [[ "$css_count" -gt 0 ]] || fail "No built CSS assets found in $FRONTEND_PUBLIC_DIR/assets"

  (cd "$SERVER_APP_DIR/backend" && php artisan route:list --path=api/v1/status --no-interaction) | grep -q 'api/v1/status' \
    || fail "Laravel /api/v1/status route is missing after frontend sync"
}

timestamp="$(date +%Y%m%d%H%M%S)"
backup_path="$SERVER_BACKUP_ROOT/$timestamp"

[[ -d "$SERVER_APP_DIR/.git" ]] || fail "Server app checkout is missing: $SERVER_APP_DIR"
mkdir -p "$backup_path"
chmod 700 "$backup_path"

log "Creating backup at $backup_path"
git -C "$SERVER_APP_DIR" rev-parse HEAD > "$backup_path/commit-before.txt"
if [[ -f "$APACHE_CONF" ]]; then
  cp "$APACHE_CONF" "$backup_path/ccc.razbudise.mk.conf"
fi

if [[ -f "$SERVER_DB_PATH" ]]; then
  mkdir -p "$backup_path/database"
  cp "$SERVER_DB_PATH" "$backup_path/database/database.sqlite"
fi

tar \
  --exclude='.git' \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='dist' \
  --exclude='backend/storage/logs/*' \
  --exclude='backend/storage/framework/cache/*' \
  --exclude='backend/storage/framework/views/*' \
  -czf "$backup_path/app-files.tar.gz" \
  -C "$SERVER_APP_DIR" .

log "Pulling latest $MEDIAHUB_REMOTE/$MEDIAHUB_BRANCH"
cd "$SERVER_APP_DIR"
git fetch "$MEDIAHUB_REMOTE" "$MEDIAHUB_BRANCH"
git checkout "$MEDIAHUB_BRANCH"
git pull --ff-only "$MEDIAHUB_REMOTE" "$MEDIAHUB_BRANCH"

log "Installing Laravel dependencies"
cd "$SERVER_APP_DIR/backend"
composer install --no-dev --optimize-autoloader

if [[ -f .env ]] && ! grep -q '^APP_KEY=base64:' .env; then
  log "Generating missing Laravel app key"
  php artisan key:generate --force --no-interaction
fi

log "Running Laravel migrations and caches"
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

if [[ ! -L public/storage && ! -e public/storage ]]; then
  php artisan storage:link --relative
fi

if php artisan list --raw | grep -q '^filament:assets$'; then
  php artisan filament:assets
fi

log "Building React frontend"
cd "$SERVER_APP_DIR"
npm ci
npm run build -- --emptyOutDir
sync_frontend_build

log "Testing Apache configuration"
apachectl configtest

if [[ -n "$PHP_FPM_SERVICE" ]]; then
  log "Reloading $PHP_FPM_SERVICE"
  systemctl reload "$PHP_FPM_SERVICE"
fi

if [[ "$RUN_APACHE_RELOAD" == "true" ]]; then
  log "Reloading Apache"
  systemctl reload apache2
else
  log "Skipping Apache reload because MEDIAHUB_RELOAD_APACHE is not true"
fi

printf 'BACKUP_PATH=%s\n' "$backup_path"
printf 'DEPLOYED_COMMIT=%s\n' "$(git -C "$SERVER_APP_DIR" rev-parse HEAD)"
REMOTE_DEPLOY
}

scan_forbidden_payload_keys() {
  local file="$1"
  local key

  for key in "${FORBIDDEN_PAYLOAD_KEYS[@]}"; do
    if grep -Eiq "\"?$key\"?[[:space:]]*:" "$file"; then
      fail "Sensitive payload key found in smoke response: $key"
    fi
  done
}

run_live_smoke() {
  verify_live_protection

  local tmpdir
  tmpdir="$(mktemp -d)"
  trap 'rm -rf "${tmpdir:-}"' RETURN

  log "Checking public /api/v1/status"
  curl -fsS "$LIVE_URL/api/v1/status" > "$tmpdir/status.json"

  if [[ -n "${MEDIAHUB_APP_EMAIL:-}" && -n "${MEDIAHUB_APP_PASSWORD:-}" ]]; then
    log "Checking authenticated app payloads without printing credentials"
    local cookie_jar payload
    cookie_jar="$tmpdir/cookies.txt"
    payload="$(
      MEDIAHUB_APP_EMAIL="$MEDIAHUB_APP_EMAIL" MEDIAHUB_APP_PASSWORD="$MEDIAHUB_APP_PASSWORD" python3 - <<'PY'
import json
import os

print(json.dumps({
    "email": os.environ["MEDIAHUB_APP_EMAIL"],
    "password": os.environ["MEDIAHUB_APP_PASSWORD"],
}))
PY
    )"

    curl -fsS -c "$cookie_jar" -b "$cookie_jar" \
      -H 'Accept: application/json' \
      -H 'Content-Type: application/json' \
      -X POST \
      --data "$payload" \
      "$LIVE_URL/api/v1/auth/login" \
      > "$tmpdir/login.json"

    local endpoint file
    for endpoint in dashboard media-events media-events/recent player/items; do
      file="$tmpdir/${endpoint//\//_}.json"
      curl -fsS -c "$cookie_jar" -b "$cookie_jar" \
        -H 'Accept: application/json' \
        "$LIVE_URL/api/v1/$endpoint" \
        > "$file"
      scan_forbidden_payload_keys "$file"
    done
  else
    log "Skipping app-login smoke: MEDIAHUB_APP_EMAIL/PASSWORD are not set"
  fi

  rm -rf "$tmpdir"
  trap - RETURN
  log "Live smoke checks completed"
}

main() {
  require_local_tools
  verify_git_state
  verify_ssh_access
  verify_live_protection

  if [[ "$MODE" == "check" ]]; then
    log "Check mode complete; no deployment was run"
    exit 0
  fi

  run_remote_deploy
  run_live_smoke
  log "Deployment finished"
}

main
