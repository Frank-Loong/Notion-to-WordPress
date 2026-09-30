# WordPress runtime integration tests

These tests exercise the installed plugin inside a real WordPress runtime. They
are separate from `scripts/integration-test.js`, which validates the release and
packaging toolchain.

## Safety boundary

The target site's `wp-config.php` must contain:

```php
define( 'NTWP_INTEGRATION_TEST', true );
```

The runner exits before making fixture changes when this marker is absent. Use
only a disposable local or CI WordPress site. Never add the marker to a
production site.

Credentials are never printed. The runner first checks environment variables
and then falls back to settings saved in the disposable WordPress site:

- `NTWP_TEST_WP_ROOT`: absolute path to the WordPress installation.
- `NTWP_NOTION_TOKEN`: optional Notion integration token override.
- `NTWP_NOTION_DATABASE_ID`: optional database/data-source ID override.
- `NTWP_TEST_BASE_URL`: optional site URL for frontend HTTP checks.
- `NTWP_TEST_ALLOW_MUTATION=1`: required by `full` mode because it updates the
  isolated WordPress site's imported posts. It does not write to Notion.

## Modes

```text
smoke         Connection, source count, uniqueness, non-empty content, frontend.
incremental   Smoke checks plus count and content-hash idempotency.
full          Force a full WordPress sync, then run the smoke assertions.
lifecycle     Mock first import and identical second import; cleans its fixtures.
content-fixture Run the core block matrix through WordPress save and parse_blocks.
media-fixture   Download a local image, rotate its signature, and verify dedup.
media-concurrency Download 5, 20, then 50 unique images with bounded concurrency.
attachment-fixture Persist file/PDF/video/audio URLs, deduplicate, and retain safely.
failure-guard Mock content transport failure and assert body/cursor preservation.
```

Run the local-only modes with Composer:

```bash
composer test:integration:lifecycle
composer test:integration:content
composer test:integration:media
composer test:integration:media-concurrency
composer test:integration:attachments
composer test:integration:failure
```

The modes that read Notion require the environment variables above:

```bash
composer test:integration
composer test:integration:incremental
php tests/integration/wordpress-runtime.php full
```

Every mode emits JSON and exits non-zero when any assertion fails, so CI can
store the output as a test artifact and use the exit status as a release gate.
