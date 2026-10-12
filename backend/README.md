# ReelAtlas backend

Laravel provides ReelAtlas's authenticated API, user-scoped domain model, administrative review surface, import/export boundaries, and background jobs.

## Local setup

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --graceful
php artisan serve
```

Use only synthetic or approved local data. Private imports, exports, databases, logs, credentials, and provider configuration must remain in ignored storage.

## Validation

```bash
composer validate --strict
php artisan test
vendor/bin/pint --test
composer audit
php artisan config:cache
php artisan route:cache
```

## Boundaries

- Every personal record is scoped to its owner.
- Canonical history survives optional provider removal.
- Public profiles expose only allowlisted fields.
- Raw locators, credentials, tokens, private notes, and exports are never public payloads.
- TV Time references describe import compatibility, not ReelAtlas branding.

The public architecture and documentation map live in the [repository README](../README.md).
