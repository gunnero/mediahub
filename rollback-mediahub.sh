#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

usage() {
  cat <<'USAGE'
Usage:
  ./rollback-mediahub.sh --yes

Restores the latest MediaHub staging backup on web01, reloads Apache, and
verifies the public login, Laravel authentication, and noindex protection.

Environment variables:
  MEDIAHUB_ROLLBACK_BACKUP=/home/razbudise/ccc.razbudise.mk/backups/20260705004417
  MEDIAHUB_SSH_HOST=web01
  MEDIAHUB_SSH_USER=root
  MEDIAHUB_SSH_IDENTITY=$HOME/.ssh/123url_ed25519
  MEDIAHUB_SERVER_SITE_ROOT=/home/razbudise/ccc.razbudise.mk
  MEDIAHUB_LIVE_URL=https://ccc.razbudise.mk
USAGE
}

log() {
  printf '[mediahub rollback] %s\n' "$*"
}

fail() {
  printf '[mediahub rollback] ERROR: %s\n' "$*" >&2
  exit 1
}

quote() {
  printf '%q' "$1"
}

CONFIRMED="false"
for arg in "$@"; do
  case "$arg" in
    --yes)
      CONFIRMED="true"
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

if [[ "$CONFIRMED" != "true" ]]; then
  usage
  fail "Rollback is destructive. Re-run with --yes after selecting the correct backup."
fi

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
ROLLBACK_BACKUP="${MEDIAHUB_ROLLBACK_BACKUP:-}"

SSH_ARGS=(
  -i "$SSH_IDENTITY"
  -o BatchMode=yes
  -o IdentitiesOnly=yes
  -o StrictHostKeyChecking=accept-new
)

verify_ssh_access() {
  [[ -r "$SSH_IDENTITY" ]] || fail "SSH identity is not readable: $SSH_IDENTITY"

  log "Checking SSH access to $SSH_TARGET"
  if ! ssh "${SSH_ARGS[@]}" "$SSH_TARGET" 'printf "ssh-ok:%s\n" "$(hostname)"' >/dev/null; then
    fail "SSH failed. See docs/infrastructure/SSH_SETUP.md"
  fi
}

run_remote_rollback() {
  local remote_command
  remote_command="SERVER_SITE_ROOT=$(quote "$SERVER_SITE_ROOT")"
  remote_command+=" SERVER_APP_DIR=$(quote "$SERVER_APP_DIR")"
  remote_command+=" SERVER_BACKUP_ROOT=$(quote "$SERVER_BACKUP_ROOT")"
  remote_command+=" SERVER_DB_PATH=$(quote "$SERVER_DB_PATH")"
  remote_command+=" APACHE_CONF=$(quote "$APACHE_CONF")"
  remote_command+=" MEDIAHUB_ROLLBACK_BACKUP=$(quote "$ROLLBACK_BACKUP")"
  remote_command+=" bash -s"

  ssh "${SSH_ARGS[@]}" "$SSH_TARGET" "$remote_command" <<'REMOTE_ROLLBACK'
set -Eeuo pipefail
IFS=$'\n\t'

log() {
  printf '[mediahub remote rollback] %s\n' "$*"
}

fail() {
  printf '[mediahub remote rollback] ERROR: %s\n' "$*" >&2
  exit 1
}

if [[ -z "${MEDIAHUB_ROLLBACK_BACKUP:-}" ]]; then
  MEDIAHUB_ROLLBACK_BACKUP="$(ls -1dt "$SERVER_BACKUP_ROOT"/* 2>/dev/null | head -n 1 || true)"
fi

[[ -n "$MEDIAHUB_ROLLBACK_BACKUP" ]] || fail "No backup found under $SERVER_BACKUP_ROOT"
[[ -d "$MEDIAHUB_ROLLBACK_BACKUP" ]] || fail "Rollback backup does not exist: $MEDIAHUB_ROLLBACK_BACKUP"
[[ -f "$MEDIAHUB_ROLLBACK_BACKUP/app-files.tar.gz" ]] || fail "Backup is missing app-files.tar.gz"

pre_rollback="$SERVER_BACKUP_ROOT/pre-rollback-$(date +%Y%m%d%H%M%S)"
mkdir -p "$pre_rollback"
chmod 700 "$pre_rollback"

log "Creating pre-rollback snapshot at $pre_rollback"
git -C "$SERVER_APP_DIR" rev-parse HEAD > "$pre_rollback/commit-before-rollback.txt" || true
if [[ -f "$SERVER_DB_PATH" ]]; then
  mkdir -p "$pre_rollback/database"
  cp "$SERVER_DB_PATH" "$pre_rollback/database/database.sqlite"
fi

log "Restoring app files from $MEDIAHUB_ROLLBACK_BACKUP"
tar -xzf "$MEDIAHUB_ROLLBACK_BACKUP/app-files.tar.gz" -C "$SERVER_APP_DIR"

if [[ -f "$MEDIAHUB_ROLLBACK_BACKUP/database/database.sqlite" ]]; then
  log "Restoring database.sqlite"
  mkdir -p "$(dirname "$SERVER_DB_PATH")"
  cp "$MEDIAHUB_ROLLBACK_BACKUP/database/database.sqlite" "$SERVER_DB_PATH"
fi

if [[ -f "$MEDIAHUB_ROLLBACK_BACKUP/ccc.razbudise.mk.conf" ]]; then
  log "Restoring Apache vhost backup"
  cp "$MEDIAHUB_ROLLBACK_BACKUP/ccc.razbudise.mk.conf" "$APACHE_CONF"
fi

log "Testing Apache configuration"
apachectl configtest

log "Reloading Apache"
systemctl reload apache2

printf 'RESTORED_BACKUP=%s\n' "$MEDIAHUB_ROLLBACK_BACKUP"
printf 'PRE_ROLLBACK_SNAPSHOT=%s\n' "$pre_rollback"
REMOTE_ROLLBACK
}

verify_live_protection() {
  local headers status private_status
  headers="$(mktemp)"
  status="$(curl -sS -o /dev/null -D "$headers" -w '%{http_code}' "$LIVE_URL/" || true)"

  if [[ "$status" != "200" ]]; then
    rm -f "$headers"
    fail "Expected the public MediaHub login page to return 200 after rollback, got $status"
  fi

  if grep -iq '^www-authenticate:' "$headers"; then
    rm -f "$headers"
    fail "Unexpected Apache Basic Auth challenge after rollback"
  fi

  if ! grep -iq '^x-robots-tag:.*noindex' "$headers"; then
    cat "$headers" >&2
    rm -f "$headers"
    fail "Expected X-Robots-Tag noindex header after rollback"
  fi

  private_status="$(curl -sS -H 'Accept: application/json' -o /dev/null -w '%{http_code}' "$LIVE_URL/api/v1/me" || true)"
  rm -f "$headers"

  if [[ "$private_status" != "401" ]]; then
    fail "Expected unauthenticated Laravel /api/v1/me to return 401 after rollback, got $private_status"
  fi

  log "Rollback verification passed"
}

main() {
  verify_ssh_access
  run_remote_rollback
  verify_live_protection
  log "Rollback finished"
}

main
