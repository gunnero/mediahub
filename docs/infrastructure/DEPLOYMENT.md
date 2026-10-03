# MediaHub deployment

The default workflow prepares an immutable release in an isolated build directory,
then applies it locally on the configured host as the existing application owner.
Infrastructure values stay in the ignored `.mediahub-deploy.env` profile. Do not
commit real hostnames, usernames, server paths, or credentials.

## Private profile

Copy `.mediahub-deploy.env.example` to `.mediahub-deploy.env` and set mode `0600`.
Set `MEDIAHUB_DEPLOY_TRANSPORT=local`, the expected hostname, application owner,
checkout and backup directories, and HTTPS URL. The production checkout must be
on the configured branch with no local changes. This workflow supports the
existing SQLite database at `backend/database/database.sqlite`.

Preparation needs Git, Node, npm, Python, tar, and SHA-256 tools. The application
host needs Git, PHP with the application's extensions, Composer, Python with
SQLite, tar, curl, and flock. Node is not needed in production.

## Prepare and review

Merge only after required CI passes. The source checkout must be clean and match
the pushed branch. Run:

```bash
./deploy-mediahub.sh --check
./deploy-mediahub.sh --output=/absolute/release/directory
```

The first command checks the local source/profile without accessing production.
The second builds from a Git archive with `npm ci` and `npm run build`, excluding
ignored environments, databases, and local artifacts. It retains the isolated
build directory and reports its path.

The package contains the exact Git commit and bundle, built frontend, deployment
scripts, a SHA-256 manifest, and only the non-secret deployment settings needed
by the runner. No environment credentials, SSH keys, or database are packaged.
Its files must be readable by the application owner. Keep it outside the web root
and transfer it intact to the configured host if built elsewhere.

Review the generated package and run its printed command as the application owner
using the server's existing access. Append `--check` for production preflight.
A request for interactive sudo authentication must be completed in the operator's
terminal; passwords do not belong in chat or the release package.

## Production preflight and apply

The runner verifies the package checksums, expected host and user, clean branch,
write access, PHP extensions, configured application key, exact runtime SQLite
path, disk space, live root/status/session endpoints, and anonymous private-API
protection. It locks against concurrent releases and fetches the packaged commit
into Git. `--check` stops before backups, maintenance, or application changes;
it can add Git objects and extract the frontend into a temporary directory.

The target must fast-forward production. Any changed migration file stops the
release for a separate migration/recovery review; migrations are never run
implicitly. A repeated release verifies that the live frontend already matches.

Before changing code, the runner creates a private recovery backup, then briefly
puts Laravel into maintenance mode. Dependencies and public Filament assets use
umask `022`; configuration caches and backups use `077`. No service reload,
cron installation, permission grant, or environment change is performed.

Frontend validation permits only the index, known icons/manifest, and assets.
It rejects symlinks and unexpected files. New static files are copied atomically
with `0644` permissions and asset directories use `0755`, regardless of inherited
umask. Old assets, Laravel entry points, and the storage symlink are preserved.

Before replacing the live index, the runner fetches a staged HTML file and each
referenced JavaScript/CSS asset over HTTPS and compares their bytes with the
package. Failed permissions or content checks leave the previous index in place.
Only after those checks pass does it atomically publish the new index, end
maintenance, and repeat live health, asset, and authentication-boundary checks.

## Backups and recovery

Each timestamped backup directory has mode `0700`. It contains:

- the before/after commit identifiers and previous Git bundle;
- the previous vendor/public files, excluding the storage symlink;
- a consistent SQLite backup that passes `quick_check`;
- a relative SHA-256 manifest and an environment fingerprint.

The environment file itself is not copied. These are local recovery backups;
independent backup storage and retention remain operator responsibilities.

On failure, the runner prints the backup path and maintenance status. Failure
after maintenance starts leaves maintenance enabled for inspection. Do not rerun
blindly or restore the database: a successful write made by a user after the
backup must not be discarded. Prefer a forward repair or a reviewed revert on
the source branch, followed by another tested package. Restoring saved code or
runtime files requires inspecting the recorded versions and preserving any local
changes. Database restoration is a separate, explicitly reviewed recovery action.

## Legacy SSH scripts

The previous root-oriented SSH workflow is retained only for separately verified
legacy environments. It requires the explicit value
`MEDIAHUB_DEPLOY_TRANSPORT=ssh` plus its SSH and Apache settings. An old profile
without a transport value is rejected. The local profile cannot invoke the legacy
rollback script, which restores a database and server configuration in addition
to code. Do not use that rollback script for a local release package.

Local package deployments preserve the existing server configuration and scheduler.
Provisioning or changing those services is separate administration work.
