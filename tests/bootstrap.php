<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Preload the real WP_Hook engine for the ComposePress\Core\Testing fakes when
// the vendored WordPress core (roots/wordpress-no-content) is present. The
// roots/wordpress-core-installer plugin relocates it to var/wordpress; when the
// plugin is not active it stays under vendor/roots/wordpress-no-content.
$wpHookCandidates = [
    (getenv('WP_CORE_DIR') ?: dirname(__DIR__) . '/var/wordpress') . '/wp-includes/class-wp-hook.php',
    dirname(__DIR__) . '/vendor/roots/wordpress-no-content/wp-includes/class-wp-hook.php',
];
foreach ($wpHookCandidates as $wpHookFile) {
    if (is_file($wpHookFile)) {
        require_once $wpHookFile;
        break;
    }
}

if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', '/tmp/wordpress/wp-content/plugins');
}

// The plugin root, used by the faithful plugin_basename()/plugins_url() shims
// below to derive the `cast` plugin-folder segment exactly as core does.
$GLOBALS['lumeweb_cast_plugin_root'] = dirname(__DIR__);

$GLOBALS['lumeweb_cast_hooks'] = [];
$GLOBALS['lumeweb_cast_lifecycle'] = [];
$GLOBALS['lumeweb_cast_options'] = [];
$GLOBALS['lumeweb_cast_network_options'] = [];

function add_action(string $hook, callable $callback, int $priority = 10, int $arguments = 1): bool
{
    $GLOBALS['lumeweb_cast_hooks'][] = ['action', $hook, $priority, $arguments];
    return true;
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $arguments = 1): bool
{
    $GLOBALS['lumeweb_cast_hooks'][] = ['filter', $hook, $priority, $arguments];
    return true;
}

function remove_action(string $hook, string|callable $callback, int $priority = 10): bool
{
    $GLOBALS['lumeweb_cast_hooks'][] = ['remove_action', $hook, $priority];
    return true;
}

function remove_filter(string $hook, string|callable $callback, int $priority = 10): bool
{
    $GLOBALS['lumeweb_cast_hooks'][] = ['remove_filter', $hook, $priority];
    return true;
}

function register_activation_hook(string $file, callable $callback): void
{
    $GLOBALS['lumeweb_cast_lifecycle'][] = ['activate', $file, $callback];
}

function register_deactivation_hook(string $file, callable $callback): void
{
    $GLOBALS['lumeweb_cast_lifecycle'][] = ['deactivate', $file, $callback];
}

function register_uninstall_hook(string $file, callable $callback): void
{
    $GLOBALS['lumeweb_cast_lifecycle'][] = ['uninstall', $file, $callback];
}

function get_option(string $option, mixed $default = false): mixed
{
    return $GLOBALS['lumeweb_cast_options'][$option] ?? $default;
}

function add_option(string $option, mixed $value = '', string $deprecated = '', bool $autoload = true): bool
{
    $GLOBALS['lumeweb_cast_last_option_autoload'][$option] = $autoload;
    $GLOBALS['lumeweb_cast_options'][$option] = $value;
    return true;
}

function update_option(string $option, mixed $value, bool $autoload = true): bool
{
    $GLOBALS['lumeweb_cast_last_option_autoload'][$option] = $autoload;
    $GLOBALS['lumeweb_cast_options'][$option] = $value;
    return true;
}

function delete_option(string $option): bool
{
    unset($GLOBALS['lumeweb_cast_options'][$option]);
    return true;
}

function get_network_option(int|null $networkId, string $option, mixed $default = false): mixed
{
    return $GLOBALS['lumeweb_cast_network_options'][$option] ?? $default;
}

function update_network_option(int|null $networkId, string $option, mixed $value): bool
{
    $GLOBALS['lumeweb_cast_network_options'][$option] = $value;
    return true;
}

function delete_network_option(int|null $networkId, string $option): bool
{
    unset($GLOBALS['lumeweb_cast_network_options'][$option]);
    return true;
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $url): string
{
    return $url;
}

$GLOBALS['lumeweb_cast_plugins'] = [];
$GLOBALS['lumeweb_cast_active_plugins'] = [];

/**
 * @return array<string, array<string, string>>
 */
function get_plugins(string $pluginFolder = ''): array
{
    return $GLOBALS['lumeweb_cast_plugins'];
}

function is_plugin_active(string $plugin): bool
{
    return in_array($plugin, $GLOBALS['lumeweb_cast_active_plugins'], true);
}

function is_plugin_inactive(string $plugin): bool
{
    return !is_plugin_active($plugin);
}

$GLOBALS['lumeweb_cast_user_meta'] = [];
$GLOBALS['lumeweb_cast_current_user_id'] = 0;

function get_user_meta(int $userId, string $key, bool $single = false): mixed
{
    $value = $GLOBALS['lumeweb_cast_user_meta'][$userId][$key] ?? '';

    return $single ? $value : [$value];
}

function update_user_meta(int $userId, string $key, mixed $value, mixed $previous = ''): bool
{
    $GLOBALS['lumeweb_cast_user_meta'][$userId][$key] = $value;

    return true;
}

function delete_user_meta(int $userId, string $key, mixed $value = ''): bool
{
    unset($GLOBALS['lumeweb_cast_user_meta'][$userId][$key]);

    return true;
}

function get_current_user_id(): int
{
    return $GLOBALS['lumeweb_cast_current_user_id'];
}

$GLOBALS['lumeweb_cast_menu_pages'] = [];
$GLOBALS['lumeweb_cast_current_user_can'] = true;
$GLOBALS['lumeweb_cast_redirects'] = [];

function add_menu_page(
    string $pageTitle,
    string $menuTitle,
    string $capability,
    string $menuSlug,
    mixed $callback = '',
    string $iconUrl = '',
    int $position = 0
): string {
    $GLOBALS['lumeweb_cast_menu_pages'][] = [
        $pageTitle,
        $menuTitle,
        $capability,
        $menuSlug,
        $callback,
    ];

    return $menuSlug;
}

function current_user_can(string $capability, mixed ...$args): bool
{
    return $GLOBALS['lumeweb_cast_current_user_can'];
}

function wp_nonce_field(mixed $action = -1, string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
{
    $field = 'NONCE_FIELD_' . $action;

    if ($echo) {
        echo $field;
    }

    return $field;
}

function wp_verify_nonce(string $nonce, mixed $action = -1): int|false
{
    return ($GLOBALS['lumeweb_cast_verify_nonce'] ?? true) ? 1 : false;
}

function wp_create_nonce(mixed $action = -1): string
{
    return 'NONCE_' . $action;
}

function admin_url(string $path = '', string $scheme = 'admin'): string
{
    return 'http://example.test/wp-admin/' . ltrim($path, '/');
}

function wp_safe_redirect(string $location, int $status = 302, string $xRedirectBy = 'WordPress'): bool
{
    $GLOBALS['lumeweb_cast_redirects'][] = $location;

    return true;
}

$GLOBALS['lumeweb_cast_enqueued_scripts'] = [];
$GLOBALS['lumeweb_cast_localized_scripts'] = [];

/**
 * @param list<string> $deps
 */
function wp_enqueue_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, mixed $args = []): void
{
    $GLOBALS['lumeweb_cast_enqueued_scripts'][] = [
        $handle,
        $src,
        $deps,
        $ver,
    ];
}

/**
 * @param array<string, mixed> $data
 */
function wp_localize_script(string $handle, string $objectName, array $data): bool
{
    $GLOBALS['lumeweb_cast_localized_scripts'][] = [
        $handle,
        $objectName,
        $data,
    ];

    return true;
}

/**
 * Faithful WP `plugins_url` shim.
 *
 * Mirrors core's folder-derivation: the plugin-folder segment is
 * dirname(plugin_basename($plugin)); when $plugin is the plugin root
 * DIRECTORY its basename has no folder (dirname('cast') === '.'), so the
 * folder is dropped and the URL loses the `cast` segment — exactly the
 * regression this suite guards against. Passing the plugin MAIN FILE
 * ('<root>/cast.php') yields dirname('cast/cast.php') === 'cast' and a
 * well-formed URL.
 */
function plugin_basename(string $file): string
{
    $root = rtrim($GLOBALS['lumeweb_cast_plugin_root'], '/');
    $file = str_replace('\\', '/', $file);

    if ($file === $root) {
        return 'cast';
    }

    if (str_starts_with($file, $root . '/')) {
        return 'cast' . substr($file, strlen($root));
    }

    return basename($file);
}

function plugins_url(string $path = '', string $plugin = ''): string
{
    $url = 'http://example.test/wp-content/plugins';

    if ($plugin !== '') {
        $folder = dirname(plugin_basename($plugin));
        if ($folder !== '.' && $folder !== '' && $folder !== '/') {
            $url .= '/' . ltrim($folder, '/');
        }
    }

    return $url . '/' . ltrim($path, '/');
}

$GLOBALS['lumeweb_cast_enqueued_styles'] = [];

/**
 * @param list<string> $deps
 */
function wp_enqueue_style(
    string $handle,
    string $src = '',
    array $deps = [],
    mixed $ver = false,
    string $media = 'all'
): void {
    $GLOBALS['lumeweb_cast_enqueued_styles'][] = [
        $handle,
        $src,
        $deps,
        $ver,
        $media,
    ];
}

$GLOBALS['lumeweb_cast_removed_meta_boxes'] = [];

/**
 * Records dashboard-widget removal so the decluttering policy is testable
 * through callback behaviour (matches core's remove_meta_box signature).
 */
function remove_meta_box(string $id, mixed $screen, string $context): bool
{
    $GLOBALS['lumeweb_cast_removed_meta_boxes'][] = [$id, (string) $screen, $context];

    return true;
}
