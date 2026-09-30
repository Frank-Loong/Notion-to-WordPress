# Developer Guide

## Requirements

- Node.js 18+
- PHP 8.0+ and Composer
- WordPress 5.0+ for manual plugin testing

## Setup

```bash
npm ci
composer install
```

## Daily checks

```bash
npm run check
vendor/bin/phpcs
vendor/bin/phpstan analyse --no-progress
vendor/bin/phpunit
```

`npm run check` runs Prettier, ESLint, TypeScript, and Jest. Do not use
auto-fixing commands in CI; make formatting changes deliberately before review.

## Build a plugin ZIP

```bash
npm run build
npm run build:verify
```

The build creates `build/notion-to-wordpress-<version>.zip`. Verification checks
all release-version declarations and the archive contents. The ZIP is
self-contained: the plugin uses its built-in `NTWP` autoloader and does not
require Composer's `vendor/` directory at runtime.

## Release

Update the version consistently in the plugin header and constant,
`package.json`, and `readme.txt`; then push a matching `v<version>` tag. The
release workflow reruns the required Node and PHP checks before creating a
GitHub Release. A failed check prevents publication.
