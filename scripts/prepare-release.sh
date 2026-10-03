#!/usr/bin/env bash
# Called by deploy-mediahub.sh after loading the ignored private profile.
set -Eeuo pipefail
umask 022
root="$(cd "$(dirname "$0")/.." && pwd)"
fail() { printf '[mediahub prepare] %s\n' "$*" >&2; exit 1; }
output=""
mode=prepare
for arg in "$@"; do
  case "$arg" in
    --output=*) output="${arg#--output=}" ;;
    --check) mode=check ;;
    --help|-h) printf 'Usage: ./deploy-mediahub.sh --check | --output=/absolute/release/directory\n'; exit 0 ;;
    *) fail "Unknown option: $arg" ;;
  esac
done
for key in MEDIAHUB_SERVER_HOSTNAME MEDIAHUB_SERVER_USER MEDIAHUB_SERVER_APP_DIR MEDIAHUB_SERVER_BACKUP_ROOT MEDIAHUB_LIVE_URL; do
  [[ -n "${!key:-}" && "${!key}" != *$'\n'* ]] || fail "Missing or invalid $key"
done
for key in MEDIAHUB_SERVER_APP_DIR MEDIAHUB_SERVER_BACKUP_ROOT; do [[ "${!key}" == /* && "${!key}" != / ]] || fail "$key must be an absolute directory"; done
[[ "$MEDIAHUB_LIVE_URL" == https://* ]] || fail 'Live URL must use HTTPS'
for tool in git node npm python3 tar sha256sum; do command -v "$tool" >/dev/null || fail "Missing tool: $tool"; done
branch="${MEDIAHUB_BRANCH:-main}"
remote="${MEDIAHUB_REMOTE:-origin}"
cd "$root"
[[ "$(git branch --show-current)" == "$branch" ]] || fail "Expected branch $branch"
[[ -z "$(git status --porcelain)" ]] || fail 'Source checkout must be clean'
git fetch --quiet "$remote" "$branch"
target="$(git rev-parse HEAD)"
[[ "$target" == "$(git rev-parse "$remote/$branch")" ]] || fail 'Source must match the pushed branch'
if [[ "$mode" == check ]]; then printf 'Source/profile verified at %s. Production has not been accessed.\n' "$target"; exit 0; fi
[[ "$output" == /* && ! -e "$output" ]] || fail '--output must name a new absolute directory'
build="$(mktemp -d)"
trap 'printf "Isolated build retained at %s\n" "$build"' EXIT
git archive HEAD | tar -x -C "$build"
(cd "$build" && npm ci && npm run build)
python3 "$root/scripts/release-assets.py" validate "$build/dist"
mkdir -m 755 "$output"
printf '%s\n' "$target" > "$output/COMMIT"
git bundle create "$output/source.bundle" HEAD
tar -czf "$output/frontend.tar.gz" -C "$build/dist" .
cp "$root/scripts/deploy-release.sh" "$output/deploy.sh"
cp "$root/scripts/release-assets.py" "$output/release-assets.py"
{
  for key in MEDIAHUB_SERVER_HOSTNAME MEDIAHUB_SERVER_USER MEDIAHUB_SERVER_APP_DIR MEDIAHUB_SERVER_BACKUP_ROOT MEDIAHUB_LIVE_URL; do printf '%s=%q\n' "$key" "${!key}"; done
  printf 'MEDIAHUB_BRANCH=%q\n' "$branch"
} > "$output/release.env"
chmod 644 "$output/COMMIT" "$output/source.bundle" "$output/frontend.tar.gz" "$output/deploy.sh" "$output/release-assets.py" "$output/release.env"
(cd "$output" && sha256sum COMMIT source.bundle frontend.tar.gz deploy.sh release-assets.py release.env > SHA256SUMS && chmod 644 SHA256SUMS && sha256sum --check SHA256SUMS)
printf 'Prepared %s at %s\n' "$target" "$output"
printf 'On the configured server, run: sudo -u %q bash %q\n' "$MEDIAHUB_SERVER_USER" "$output/deploy.sh"
printf 'Append --check for production preflight without applying the release.\n'
