# Integration harness

The real WordPress DB-backed integration suite boots a **pinned WordPress 7.1**
install against a test-only MariaDB and runs full PHPUnit integration tests
(the first of which is `tests/Integration/BootTest.php` — the plugin boots under
a live WordPress + live database).

## What it needs

- `WP_TESTS_DIR` — a configured WordPress **test suite** (its `includes/`
  directory). This is produced by `bin/install-wp-tests.sh`, which **pins the
  WordPress version to 7.1** (never `latest`/`trunk`). It must contain
  `includes/functions.php`.
- `WP_CORE_DIR` — a WordPress **core checkout** used for `ABSPATH`. Defaults to
  the composer-pinned core: `var/wordpress` (relocated by
  `roots/wordpress-core-installer` from `roots/wordpress-no-content 7.1`),
  falling back to `vendor/roots/wordpress-no-content`.
- A test-only MariaDB matching `docker-compose.integration.yml`.

## Test-only database (Docker)

```sh
# Bring up the pinned MariaDB 10.11 test service and wait for health.
docker compose -f docker-compose.integration.yml up -d --wait

# Destroy the test volume + container when done.
docker compose -f docker-compose.integration.yml down -v
```

The service provisions a throwaway database/user with deterministic,
**non-production** credentials:

| Env var              | Default         | Notes                                    |
|----------------------|-----------------|------------------------------------------|
| `WP_DB_NAME`         | `cast_test`     | test database name                       |
| `WP_DB_USER`         | `cast_test`     | test user                                |
| `WP_DB_PASSWORD`     | `cast_test`     | test password                            |
| `WP_DB_HOST`         | `127.0.0.1:3307`| host port MariaDB is published on        |
| `MARIADB_ROOT_PASSWORD` | `cast_test_root` | test-only superuser (provisioning only) |

These values are test-only; never reuse them in any real environment. Defaults
are shared between `docker-compose.integration.yml` and
`tests/Integration/wp-tests-config.php`, so local and CI behave identically.

**Why MariaDB 10.11:** it is an upstream **LTS** release supported to at least
2028, comfortably exceeds WordPress 7.1's MariaDB requirement (>= 10.3), and
receives years of stable point releases — the low-risk choice for a CI test
database versus rolling 10.x feature series.

## One-time: install the pinned WP 7.1 test suite

|               |                                                        |
|---------------|--------------------------------------------------------|
| `WP_TESTS_DIR`| default `/tmp/wordpress-tests-lib`                     |
| `WP_CORE_DIR` | default `/tmp/wordpress`                               |

```sh
WP_DB_HOST=127.0.0.1:3307 bash bin/install-wp-tests.sh \
  cast_test cast_test cast_test 127.0.0.1:3307 7.1 true
```

Arguments: `db-name db-user db-pass db-host wp-version skip-db-creation`.
`wp-version` **defaults to 7.1** inside the script (it never falls back to
`latest`). Database creation is skipped because the compose MariaDB already
creates `cast_test` + user on first start.

## Run

The `test:integration` composer script runs PHPUnit against the integration
config; the bootstrap reads the DB + core paths from the env vars above:

```sh
docker compose -f docker-compose.integration.yml up -d --wait

WP_TESTS_DIR=/tmp/wordpress-tests-lib \
WP_CORE_DIR=/tmp/wordpress \
WP_DB_HOST=127.0.0.1:3307 \
WP_DB_NAME=cast_test \
WP_DB_USER=cast_test \
WP_DB_PASSWORD=cast_test \
composer test:integration
```

Equivalently: `vendor/bin/phpunit -c phpunit.integration.xml.dist` with the same
env exported. `WP_TESTS_DIR` is required by the bootstrap (it exits when unset).
`WP_CORE_DIR` may be omitted: the bootstrap falls back to the composer-pinned
7.1 core, first `var/wordpress` (relocated by `roots/wordpress-core-installer`
via `composer.json` `extra.wordpress-install-dir`), then
`vendor/roots/wordpress-no-content`.

## How the config stays env-driven (and never touches production)

`tests/Integration/bootstrap.php`:

1. Validates `WP_TESTS_DIR`, resolves `WP_CORE_DIR` (explicit env or the
   composer-pinned core), and exports it.
2. Sets `WP_TESTS_CONFIG_FILE_PATH` to the committed
   `tests/Integration/wp-tests-config.php`, which consumes the `WP_DB_*` env
   vars (with the non-production defaults above). The WP test suite therefore
   **never** reads a generated/checked-in DB config from elsewhere, and unit
   tests (`tests/bootstrap.php`) are completely unaffected — they don't load
   this file.

`bin/install-wp-tests.sh` still downloads a default `wp-tests-config.php` for
the suite directory, but it is unused because of `WP_TESTS_CONFIG_FILE_PATH`.

## GitHub Actions

`.github/workflows/ci.yml` runs the same commands:

- **lint/static/unit** matrix on PHP 8.2 / 8.3 / 8.4: `composer lint`, phpcs
  (`style`), phpstan (`analyse`), PHPUnit unit (`test`).
- **Node** tests for `assets/js` via `node --test tests/js/`.
- **integration** on PHP 8.3: starts the compose MariaDB, waits for health,
  installs the pinned 7.1 test suite, then runs PHPUnit integration. Logs and
  `docker compose logs` are uploaded on failure.

## Coverage and scope

The wizard aggregate (`Wizard`, `WizardState`), Finite 2.x-driven transition
service (`WizardService`), global option adapter (`WordPressWizardStore` for the
single non-autoloaded `cast_onboarding` option — no per-user user-meta),
request policy (`OnboardingRequestHandler`) and admin subscriber are covered by
unit tests against the `$GLOBALS['lumeweb_cast_*']` shims.

`BootTest` proves the plugin boots under a real WordPress + MariaDB. Deeper
integration (real admin page render, full nonce round-trip through
`admin-post.php`, and the no-refresh installer against live `admin-ajax.php`)
can be added on top of this harness now that a real DB runs in CI.
