# MediaHub deployment

MediaHub uses public-safe deployment scripts and a private, ignored environment
profile. Server addresses, account names, filesystem paths, and SSH key paths
must never be committed.

## Private profile

Create the local profile from the example and set its permissions:

```bash
cp .mediahub-deploy.env.example .mediahub-deploy.env
chmod 600 .mediahub-deploy.env
```

The profile is ignored by Git. `MEDIAHUB_SSH_TARGET` should be an SSH target
whose account can run `runuser`, `apachectl`, and `systemctl`. The configured
site user owns the checkout, Laravel writable directories, and frontend build
artifacts.

## Preflight and deployment

The deployment script requires a clean configured branch that exactly matches
its remote. It also requires a clean server checkout.

```bash
./deploy-mediahub.sh --check
./deploy-mediahub.sh
```

Preflight verifies SSH, the server checkout, live readiness, Laravel session
authentication, noindex protection, baseline security headers, required server
tools, SQLite integrity, a configured `APP_KEY`, and ownership of Git and build
paths. Deployment then creates a private backup, fast-forwards the checkout,
installs production dependencies, runs migrations, rebuilds Laravel caches and
frontend assets, and reloads Apache only after its configuration passes
validation.

Deployment also repairs Laravel writable-directory ownership and installs an
idempotent root crontab entry that runs Laravel's scheduler as the configured
site user once per minute. The application schedule refreshes all users'
matched TMDB episode catalogs daily, with overlap protection.

The server should use a current stable Composer release compatible with its PHP
runtime. `/usr/local/bin` takes precedence over distribution packages, and
deployment commands use `/tmp` rather than inheriting a privileged account's
temporary directory.

Frontend synchronization is additive. It copies the Vite index and generated
assets without deleting Laravel, Filament, Livewire, icons, or other public
files. Laravel's `index.php` and `.htaccess` are checked before and after sync.

## Backups

Each deployment creates a timestamped directory under the configured private
backup root with mode `0700`. It contains:

- the pre-deployment Git commit;
- an online SQLite backup when SQLite is configured;
- the Apache virtual host configuration when present;
- a SHA-256 manifest using relative paths.

Application secrets and environment files are not copied into deployment
backups. Retention is an operator decision and should follow the private-data
retention policy for the environment.

## Rollback

Rollback requires both an explicit absolute backup path and confirmation:

```bash
./rollback-mediahub.sh --backup=/absolute/private/backup/path --yes
```

The script rejects backups outside the configured root, validates the checksum
manifest and commit identifier, and creates a new pre-rollback backup before
changing anything. It restores the exact recorded commit, database, and Apache
configuration, rebuilds dependencies and assets, validates Apache, reloads it,
and verifies the live root and authenticated API boundary.

Rollback is intentionally not automatic. Never run it without reviewing the
target backup and understanding whether later database migrations are
compatible with the restored code and data.

## Failure handling

If deployment fails before Apache reload, inspect the remote output and keep
the generated backup. If it fails after code or migrations changed, use the
reported backup path for an explicit rollback. Do not manually delete files
from the public directory or loosen filesystem and Apache permissions.
