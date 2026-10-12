# Contributing

ReelAtlas is currently maintained as a source-visible personal product project. Unsolicited feature pull requests are not assumed to be accepted. Focused bug reports and security reports are welcome through the channels below.

## Before proposing a change

1. Open a concise issue for non-security defects.
2. Use the private process in [SECURITY.md](SECURITY.md) for vulnerabilities.
3. Do not include private user data, provider credentials, copyrighted artwork, operational infrastructure, or generated exports.
4. Keep changes scoped and include tests for behavior changes.

## Local validation

```bash
npm ci
npm test
npm run build
npm audit --audit-level=high
python3 -m unittest discover -s tests
composer validate --working-dir=backend --strict
composer install --working-dir=backend --no-interaction --prefer-dist
php backend/artisan test
backend/vendor/bin/pint --test
composer audit --working-dir=backend
scripts/check-public-evidence.sh
git diff --check
```

## Pull requests

Explain the problem and boundaries, link the relevant issue, list validation performed, and explicitly confirm that no personal data or operational details are included. Screenshots must follow [the screenshot policy](docs/040-mediahub-screenshot-policy.md).
