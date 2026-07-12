# MediaHub Domain Migration

This is a preparation package only. It does not change DNS, Apache, TLS, application environment, Search Console, or the current `ccc.razbudise.mk` deployment.

## Target

- Current: `https://ccc.razbudise.mk`
- Target: `https://mediahub.razbudise.mk`
- Existing checkout remains `/home/razbudise/ccc.razbudise.mk/app` during the first verified cutover so the domain change does not also move application data.

## Before Cutover

1. Add the `mediahub` DNS A and AAAA records to the same verified web01 addresses.
2. Confirm propagation with `dig mediahub.razbudise.mk A` and `dig mediahub.razbudise.mk AAAA`.
3. Copy and review `deploy/mediahub-domain/mediahub.razbudise.mk.conf.example` on web01.
4. Issue the certificate with Certbot only after the HTTP challenge resolves.
5. Run `apachectl configtest` before enabling or reloading any vhost.
6. Keep `X-Robots-Tag: noindex` and Laravel authentication active on both hosts.

## Application Configuration

After the new host passes TLS checks, update the private server environment without printing values:

```dotenv
APP_URL=https://mediahub.razbudise.mk
SESSION_SECURE_COOKIE=true
```

Rebuild `config`, `route`, and `view` caches. Update the canonical link in `index.html` from the old URL to the target URL in the same reviewed release. Replace the active `robots.txt` and sitemap only if the site is intentionally made indexable later; while this authenticated environment remains private, use the prepared blocking robots file and keep noindex.

## Deployment Script

The existing script already supports the target through environment variables:

```bash
MEDIAHUB_LIVE_URL=https://mediahub.razbudise.mk \
MEDIAHUB_APACHE_CONF=/etc/apache2/sites-available/mediahub.razbudise.mk.conf \
./deploy-mediahub.sh --check
```

Do not run a deployment or redirect until the preflight succeeds and a current application/database/vhost backup exists.

## Verification

- HTTPS certificate is valid for `mediahub.razbudise.mk`.
- Public login returns `200`; private API returns `401` when signed out.
- Login, logout, dashboard, profile avatar, discovery, calendar, alerts, lists, stats, and `/admin` work.
- Built assets, favicon, pinned-tab icon, and manifest return `200`.
- Canonical points to `https://mediahub.razbudise.mk/` only after cutover.
- `X-Robots-Tag` remains `noindex`.
- No provider URL, stream locator, key, token, password, or private media data appears in responses or logs.

## Redirect

Keep `ccc.razbudise.mk` serving the application during verification. After the target has passed all checks, apply the reviewed rule from `deploy/mediahub-domain/ccc-redirect.conf.example`. Verify path and query preservation, then leave the old certificate and vhost available for rollback.

## Search Console

The current authenticated noindex site should not be submitted for indexing. Preserve any existing Search Console verification token/file on the old host. Add and verify the new URL-prefix property before redirecting, keep both properties, and submit the target sitemap only if noindex is intentionally removed in a later public-launch decision.

## Rollback

1. Disable the `ccc` redirect rule.
2. Restore `APP_URL=https://ccc.razbudise.mk` and rebuild Laravel caches.
3. Restore the backed-up `ccc` vhost if it was changed.
4. Run `apachectl configtest` and reload Apache.
5. Verify login, private API `401`, assets, and noindex on `ccc`.
6. Leave the new DNS record and certificate in place while investigating; neither affects the restored old host.
