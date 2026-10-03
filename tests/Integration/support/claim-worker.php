<?php

/**
 * Standalone concurrent claim worker: one process, one database connection,
 * draining the claimable rows of one run through the real
 * WordPressWpDbGateway and SqlWorkItemRepository. It loads only the wpdb
 * class (no full WordPress boot) and writes the claimed url hashes, one per
 * line, to the output file.
 *
 * Usage:
 *   php claim-worker.php <wp-core-dir> <table> <run-id> <worker> <out-file>
 */

declare(strict_types=1);

if ($argc !== 6) {
    fwrite(STDERR, 'usage: php claim-worker.php <wp-core-dir> <table> <run-id> <worker> <out-file>' . PHP_EOL);
    exit(2);
}

[, $coreDir, $table, $runId, $worker, $outFile] = $argv;

// wpdb only needs these defined; the happy path never triggers error display.
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('SAVEQUERIES', false);

// Minimal WordPress function shims for a standalone (no full WP boot) wpdb.
if (!function_exists('absint')) {
    function absint(mixed $number): int
    {
        return abs((int) $number);
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
    {
        foreach ($GLOBALS['__shim_filters'][$tag] ?? [] as $callback) {
            $value = $callback($value);
        }

        return $value;
    }
}
if (!function_exists('add_filter')) {
    function add_filter(string $tag, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['__shim_filters'][$tag][] = $callback;

        return true;
    }
}
if (!function_exists('do_action')) {
    function do_action(string $tag, mixed ...$args): void
    {
    }
}
if (!function_exists('has_filter')) {
    function has_filter(string $tag, mixed $callback = true): false|int
    {
        $filters = $GLOBALS['__shim_filters'][$tag] ?? [];
        if ($filters === []) {
            return false;
        }
        if ($callback === true) {
            return 10;
        }
        foreach ($filters as $index => $filter) {
            if ($filter === $callback) {
                return $index + 1;
            }
        }

        return false;
    }
}
if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return false;
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return false;
    }
}
if (!function_exists('_deprecated_function')) {
    function _deprecated_function(string $function, string $version, ?string $replacement = null): void
    {
    }
}
if (!function_exists('wp_load_translations_early')) {
    /** @param array<string>|null $locale */
    function wp_load_translations_early(?array $locale = null): void
    {
    }
}
if (!function_exists('wp_debug_backtrace_summary')) {
    /** @return list<string> */
    function wp_debug_backtrace_summary(?string $ignore = null, bool $hide_group = false, int $skip = 0): array
    {
        return [];
    }
}

require $coreDir . '/wp-includes/class-wpdb.php';
require dirname(__DIR__, 3) . '/vendor/autoload.php';

$wpdb = new wpdb(
    getenv('WP_DB_USER') ?: 'cast_test',
    getenv('WP_DB_PASSWORD') ?: 'cast_test',
    getenv('WP_DB_NAME') ?: 'cast_test',
    getenv('WP_DB_HOST') ?: '127.0.0.1:3307',
);

$repository = new \LumeWeb\Cast\Persistence\SqlWorkItemRepository(
    new \LumeWeb\Cast\Persistence\WordPressWpDbGateway($wpdb),
    $table,
);

$claimed = [];
for ($i = 0; $i < 100; $i++) {
    $item = $repository->claimNext($runId, $worker);
    if ($item === null) {
        break;
    }
    $claimed[] = $item->urlHash();
}

if (file_put_contents($outFile, implode("\n", $claimed) . "\n") === false) {
    exit(1);
}

exit(0);
