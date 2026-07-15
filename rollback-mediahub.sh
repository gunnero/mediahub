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

log() { printf '[mediahub rollback] %s\n' "$*"; }
fail() { printf '[mediahub rollback] ERROR: %s\n' "$*" >&2; exit 1; }
quote() { printf '%q' "$1"; }

BACKUP_PATH=""
CONFIRMED="false"
for argument in "$@"; do
  case "$argument" in
    --backup=*) BACKUP_PATH="${argument#--backup=}" ;;
    --yes) CONFIRMED="true" ;;
    -h|--help)
      cat <<'USAGE'
Usage: ./rollback-mediahub.sh --backup=/absolute/server/backup/path --yes

Restores the exact Git commit, database, and Apache configuration captured by
deploy-mediahub.sh. The explicit backup path and --yes flag are both required.
USAGE
      exit 0
      ;;
    *) fail "Unknown argument: $argument" ;;
  esac
done

[[ "$CONFIRMED" == "true" ]] || fail "Rollback requires explicit --yes confirmation"
[[ "$BACKUP_PATH" == /* ]] || fail "--backup must be an absolute server path"

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

case "$BACKUP_PATH" in
  "$MEDIAHUB_SERVER_BACKUP_ROOT"/*) ;;
  *) fail "Backup must be inside MEDIAHUB_SERVER_BACKUP_ROOT" ;;
esac

BRANCH="${MEDIAHUB_BRANCH:-main}"
REMOTE="${MEDIAHUB_REMOTE:-origin}"
SERVER_DB_PATH="${MEDIAHUB_SERVER_DB_PATH:-$MEDIAHUB_SERVER_APP_DIR/backend/database/database.sqlite}"
FRONTEND_PUBLIC_DIR="${MEDIAHUB_FRONTEND_PUBLIC_DIR:-$MEDIAHUB_SERVER_APP_DIR/backend/public}"

SSH_ARGS=(-o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new)
if [[ -n "${MEDIAHUB_SSH_IDENTITY:-}" ]]; then
  [[ -r "$MEDIAHUB_SSH_IDENTITY" ]] || fail "Configured SSH identity is not readable"
  SSH_ARGS=(-i "$MEDIAHUB_SSH_IDENTITY" "${SSH_ARGS[@]}")
fi

command="SERVER_USER=$(quote "$MEDIAHUB_SERVER_USER")"
command+=" SERVER_APP_DIR=$(quote "$MEDIAHUB_SERVER_APP_DIR")"
command+=" BACKUP_ROOT=$(quote "$MEDIAHUB_SERVER_BACKUP_ROOT")"
command+=" BACKUP=$(quote "$BACKUP_PATH")"
command+=" DB_PATH=$(quote "$SERVER_DB_PATH")"
command+=" APACHE_CONF=$(quote "$MEDIAHUB_APACHE_CONF")"
command+=" PUBLIC_DIR=$(quote "$FRONTEND_PUBLIC_DIR")"
command+=" BRANCH=$(quote "$BRANCH") REMOTE=$(quote "$REMOTE") bash -s"

ssh "${SSH_ARGS[@]}" "$MEDIAHUB_SSH_TARGET" "$command" <<'REMOTE_ROLLBACK'
set -Eeuo pipefail
IFS=$'\n\t'

fail() { printf '[mediahub rollback remote] ERROR: %s\n' "$*" >&2; exit 1; }
site_home="$(getent passwd "$SERVER_USER" | cut -d: -f6)"
[[ -n "$site_home" ]] || fail "Server user does not exist"
run_as_site() { runuser -u "$SERVER_USER" -- env HOME="$site_home" TMPDIR=/tmp PATH=/usr/local/bin:/usr/bin:/bin "$@"; }
run_in_dir() {
  local directory="$1"
  shift
  run_as_site bash -c 'cd "$1"; shift; exec "$@"' _ "$directory" "$@"
}

sync_frontend() {
  [[ -f "$SERVER_APP_DIR/dist/index.html" && -d "$SERVER_APP_DIR/dist/assets" ]] || fail "React build missing"
  [[ -f "$PUBLIC_DIR/index.php" && -f "$PUBLIC_DIR/.htaccess" ]] || fail "Laravel public files missing"
  case "$PUBLIC_DIR" in ""|/|"$SERVER_APP_DIR"|"$SERVER_APP_DIR/backend") fail "Unsafe public directory" ;; esac
  run_as_site mkdir -p "$PUBLIC_DIR/assets"
  run_as_site cp "$SERVER_APP_DIR/dist/index.html" "$PUBLIC_DIR/index.html"
  run_as_site cp -a "$SERVER_APP_DIR/dist/assets/." "$PUBLIC_DIR/assets/"
  for asset in favicon.svg mediahub-pinned-tab.svg site.webmanifest; do
    [[ ! -f "$SERVER_APP_DIR/dist/$asset" ]] || run_as_site cp "$SERVER_APP_DIR/dist/$asset" "$PUBLIC_DIR/$asset"
  done
  [[ -f "$PUBLIC_DIR/index.php" && -f "$PUBLIC_DIR/.htaccess" ]] || fail "Frontend sync damaged Laravel public files"
}

[[ -d "$BACKUP" ]] || fail "Backup directory does not exist"
[[ -f "$BACKUP/commit-before.txt" && -f "$BACKUP/SHA256SUMS" ]] || fail "Backup manifest is incomplete"
(cd "$BACKUP" && sha256sum --check SHA256SUMS >/dev/null) || fail "Backup checksum verification failed"
target_commit="$(tr -d '[:space:]' < "$BACKUP/commit-before.txt")"
[[ "$target_commit" =~ ^[0-9a-f]{40}$ ]] || fail "Backup commit is invalid"

timestamp="$(date +%Y%m%d%H%M%S)"
prebackup="$BACKUP_ROOT/pre-rollback-$timestamp"
install -d -m 700 "$prebackup"
git -C "$SERVER_APP_DIR" rev-parse HEAD > "$prebackup/commit-before.txt"
[[ ! -f "$APACHE_CONF" ]] || cp "$APACHE_CONF" "$prebackup/apache.conf"
if [[ -f "$DB_PATH" ]]; then
  command -v sqlite3 >/dev/null || fail "sqlite3 is required for a consistent backup"
  sqlite3 "$DB_PATH" ".backup '$prebackup/database.sqlite'"
  chmod 600 "$prebackup/database.sqlite"
fi
(cd "$prebackup" && find . -maxdepth 1 -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS)

run_as_site git -C "$SERVER_APP_DIR" fetch "$REMOTE" "$BRANCH"
run_as_site git -C "$SERVER_APP_DIR" cat-file -e "$target_commit^{commit}"
run_as_site git -C "$SERVER_APP_DIR" checkout "$BRANCH"
run_as_site git -C "$SERVER_APP_DIR" reset --hard "$target_commit"

if [[ -f "$BACKUP/database.sqlite" ]]; then
  cp "$BACKUP/database.sqlite" "$DB_PATH"
  chown "$SERVER_USER:$SERVER_USER" "$DB_PATH"
  chmod 600 "$DB_PATH"
fi
[[ ! -f "$BACKUP/apache.conf" ]] || cp "$BACKUP/apache.conf" "$APACHE_CONF"

run_in_dir "$SERVER_APP_DIR/backend" composer install --no-dev --optimize-autoloader --no-interaction
run_in_dir "$SERVER_APP_DIR/backend" php artisan config:cache
run_in_dir "$SERVER_APP_DIR/backend" php artisan route:cache
run_in_dir "$SERVER_APP_DIR/backend" php artisan view:cache
run_in_dir "$SERVER_APP_DIR" npm ci
run_in_dir "$SERVER_APP_DIR" npm run build -- --emptyOutDir
sync_frontend

apachectl configtest
systemctl reload apache2
[[ -z "$(run_as_site git -C "$SERVER_APP_DIR" status --porcelain)" ]] || fail "Server checkout became dirty"
printf 'RESTORED_COMMIT=%s\n' "$(git -C "$SERVER_APP_DIR" rev-parse HEAD)"
printf 'PRE_ROLLBACK_BACKUP=%s\n' "$prebackup"
REMOTE_ROLLBACK

root_status="$(curl -sS -o /dev/null -w '%{http_code}' "$MEDIAHUB_LIVE_URL/" || true)"
private_status="$(curl -sS -H 'Accept: application/json' -o /dev/null -w '%{http_code}' "$MEDIAHUB_LIVE_URL/api/v1/me" || true)"
[[ "$root_status" == "200" && "$private_status" == "401" ]] || fail "Live verification failed after rollback"
log "Rollback completed and live authentication verified"
