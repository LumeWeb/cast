<?php

/**
 * Test-only WordPress configuration for the integration suite.
 *
 * This file is intentionally committed (it contains NO secrets — the values are
 * deterministic, non-production test credentials) and is wired into the WP test
 * suite via the WP_TESTS_CONFIG_FILE_PATH constant set in bootstrap.php, so the
 * test suite never touches a production config.
 *
 * Every value is consumed from an explicit env var so local runs and CI share
 * the same contract:
 *
 *   WP_DB_NAME        test database name            (default: cast_test)
 *   WP_DB_USER        test database user            (default: cast_test)
 *   WP_DB_PASSWORD    test database password        (default: cast_test)
 *   WP_DB_HOST        DB host[:port]                (default: 127.0.0.1:3307)
 *   WP_TESTS_TABLE_PREFIX                           (default: wptests_)
 *
 * The DB_HOST/IP defaults match docker-compose.integration.yml, which publishes
 * MariaDB on 127.0.0.1:3307. WP_CORE_DIR (set by bootstrap.php) supplies ABSPATH
 * pointing at the composer-pinned WordPress 7.1 core.
 */

$dbName  = getenv('WP_DB_NAME') ?: 'cast_test';
$dbUser  = getenv('WP_DB_USER') ?: 'cast_test';
$dbPass  = getenv('WP_DB_PASSWORD') ?: 'cast_test';
$dbHost  = getenv('WP_DB_HOST') ?: '127.0.0.1:3307';
$dbCharset = 'utf8mb4';
$dbCollate  = '';
$tablePrefix = getenv('WP_TESTS_TABLE_PREFIX') ?: 'wptests_';

// WordPress will throw if ABSPATH's wp-settings.php is missing; bootstrap.php
// guarantees WP_CORE_DIR points at a real (pinned 7.1) core before we get here.
$abspath = rtrim(getenv('WP_CORE_DIR') ?: '', '/') . '/';

define('DB_NAME', $dbName);
define('DB_USER', $dbUser);
define('DB_PASSWORD', $dbPass);
define('DB_HOST', $dbHost);
define('DB_CHARSET', $dbCharset);
define('DB_COLLATE', $dbCollate);

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Test Blog');

// The WP test suite shells out to PHP to install the schema; allow override
// (e.g. a php-fpm container) but default to the php on PATH.
define('WP_PHP_BINARY', getenv('WP_PHP_BINARY') ?: 'php');

define('WP_DEBUG', true);
define('WP_DEFAULT_THEME', 'default');

// The WP test suite requires yoast/phpunit-polyfills; point it at the composer
// dependency rather than relying on a global install.
define(
    'WP_TESTS_PHPUNIT_POLYFILLS_PATH',
    dirname(__DIR__, 2) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php'
);

$table_prefix = $tablePrefix;

define('ABSPATH', $abspath);
