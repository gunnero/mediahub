# MediaHub SSH Setup

This document makes the `web01` deployment SSH path explicit so deployment is not blocked by a manually loaded key.

## Current Local SSH Inventory

Relevant local files:

- `~/.ssh/config`
- `~/.ssh/123url_ed25519`
- `~/.ssh/123url_ed25519.pub`
- `~/.ssh/known_hosts`

Selected `web01` key:

- `~/.ssh/123url_ed25519`

Do not place the private key contents or key passphrase in Git, docs, scripts, shell history, or chat.

## Recommended SSH Config

Keep the deployment key explicit and load it through the macOS Keychain:

```sshconfig
Host web01
    HostName web01.123url.dk
    User root
    IdentityFile ~/.ssh/123url_ed25519
    IdentitiesOnly yes
    AddKeysToAgent yes
    UseKeychain yes

Host razbudise
    HostName web01.123url.dk
    User root
    IdentityFile ~/.ssh/123url_ed25519
    IdentitiesOnly yes
    AddKeysToAgent yes
    UseKeychain yes
```

`Host razbudise` is an optional friendly alias. The deployment scripts default to `Host web01`.

## One-Time Keychain Load

Run this once on the Mac:

```bash
ssh-add --apple-use-keychain ~/.ssh/123url_ed25519
```

If `ssh-add` asks for a passphrase, enter it locally. Do not save the passphrase in repository files.

Then verify:

```bash
ssh-add -l
ssh -i ~/.ssh/123url_ed25519 -o IdentitiesOnly=yes root@web01.123url.dk 'hostname'
./deploy-mediahub.sh --check
```

If DNS fails for `web01.123url.dk`, use the IP in a one-off check:

```bash
ssh -i ~/.ssh/123url_ed25519 -o IdentitiesOnly=yes root@188.245.47.216 'hostname'
```

## Server authorized_keys

The public key from:

```bash
~/.ssh/123url_ed25519.pub
```

must exist in the correct server account:

```text
/root/.ssh/authorized_keys
```

for the current root-based deployment flow.

Permissions on `web01` should be:

```bash
chmod 700 /root/.ssh
chmod 600 /root/.ssh/authorized_keys
chown -R root:root /root/.ssh
```

If deployment is later moved away from root, install the same public key under that deployment user's `authorized_keys` and update:

```bash
MEDIAHUB_SSH_USER=deploy
```

## Script Defaults

`deploy-mediahub.sh` and `rollback-mediahub.sh` default to:

```bash
MEDIAHUB_SSH_HOST=web01
MEDIAHUB_SSH_USER=root
MEDIAHUB_SSH_IDENTITY=$HOME/.ssh/123url_ed25519
```

Override these with environment variables only when necessary.

## Health Check Commands

Local SSH check:

```bash
ssh -i ~/.ssh/123url_ed25519 -o IdentitiesOnly=yes -o BatchMode=yes web01 'hostname'
```

Script check:

```bash
./deploy-mediahub.sh --check
```

Expected result:

- SSH connects without asking Codex to manually load a key.
- `https://ccc.razbudise.mk/` returns 401 without Basic Auth credentials.
- `X-Robots-Tag` contains `noindex`.

## If SSH Still Fails

Check these in order:

1. `~/.ssh/123url_ed25519` exists and is readable by the local user.
2. `ssh-add -l` lists the key.
3. `~/.ssh/123url_ed25519.pub` is present in `/root/.ssh/authorized_keys` on `web01`.
4. `~/.ssh/config` has `IdentityFile ~/.ssh/123url_ed25519`.
5. The server allows public-key auth for the target account.
6. The deployment script uses the same `MEDIAHUB_SSH_IDENTITY`.

Do not copy private keys into the repository or into chat.
