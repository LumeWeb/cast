<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

// ---------------------------------------------------------------------------
// WordPress test suite (the "framework" side of the integration harness).
// WP_TESTS_DIR must point at a configured WordPress test suite — the output of
// `bin/install-wp-tests.sh` (e.g. /tmp/wordpress-tests-lib), which is pinned to
// WordPress **7.1** in this repository.
// ---------------------------------------------------------------------------
$wordpressTests = getenv('WP_TESTS_DIR');
if ($wordpressTests === false || !is_file($wordpressTests . '/includes/functions.php')) {
    fwrite(
        STDERR,
        "Set WP_TESTS_DIR to a configured WordPress test suite "
        . "(e.g. /tmp/wordpress-tests-lib produced by bin/install-wp-tests.sh).\n"
    );
    exit(1);
}

// ---------------------------------------------------------------------------
// WordPress core (the "runtime" side, used for ABSPATH). Consume the explicit
// WP_CORE_DIR env var. When unset, fall back to the composer-pinned core:
// roots/wordpress-core-installer relocates roots/wordpress-no-content 7.1 into
// var/wordpress; if the installer plugin did not run it stays under
// vendor/roots/wordpress-no-content. Either way it is the pinned 7.1 core.
// ---------------------------------------------------------------------------
$wordpressCore = getenv('WP_CORE_DIR');
if ($wordpressCore === false || !is_file($wordpressCore . '/wp-settings.php')) {
    $wordpressCore = null;
    foreach ([$root . '/var/wordpress', $root . '/vendor/roots/wordpress-no-content'] as $candidate) {
        if (is_file($candidate . '/wp-settings.php')) {
            $wordpressCore = $candidate;
            break;
        }
    }
}
if ($wordpressCore === null || !is_file($wordpressCore . '/wp-settings.php')) {
    fwrite(
        STDERR,
        "Set WP_CORE_DIR to a WordPress core checkout (composer require-dev pins 7.1 "
        . "via roots/wordpress-no-content).\n"
    );
    exit(1);
}
putenv('WP_CORE_DIR=' . $wordpressCore);
$_ENV['WP_CORE_DIR'] = $wordpressCore;

// ---------------------------------------------------------------------------
// Point the WP test suite at our test-only config, which consumes explicit
// DB env vars (WP_DB_NAME / WP_DB_USER / WP_DB_PASSWORD / WP_DB_HOST). See
// tests/Integration/wp-tests-config.php for the defaults and rationale.
// ---------------------------------------------------------------------------
if (!defined('WP_TESTS_CONFIG_FILE_PATH')) {
    define('WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php');
}

require_once $root . '/vendor/autoload.php';
require_once $wordpressTests . '/includes/functions.php';
require_once $wordpressTests . '/includes/bootstrap.php';
