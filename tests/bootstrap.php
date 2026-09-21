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

// Preload the real WP_Admin_Bar class so the publish admin-bar node tests run
// against WordPress' actual node store (WP_Admin_Bar::add_node()/get_node())
// rather than a bespoke double, exactly like the WP_Hook preload above. Its
// add_node() path needs wp_parse_args(), shimmed below.
$wpAdminBarCandidates = [
    (getenv('WP_CORE_DIR') ?: dirname(__DIR__) . '/var/wordpress') . '/wp-includes/class-wp-admin-bar.php',
    dirname(__DIR__) . '/vendor/roots/wordpress-no-content/wp-includes/class-wp-admin-bar.php',
];
foreach ($wpAdminBarCandidates as $wpAdminBarFile) {
    if (is_file($wpAdminBarFile)) {
        require_once $wpAdminBarFile;
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
$GLOBALS['lumeweb_cast_option_cache'] = [];
$GLOBALS['lumeweb_cast_network_options'] = [];
$GLOBALS['lumeweb_cast_actions_fired'] = [];

// The unit harness must not write to the host error log: production code such
// as the rearm-failure warning calls error_log(), and several composition
// tests exercise that path dozens of times. Point the target at a discard sink
// (this repo's test sandbox is Linux) so tests stay quiet while a test that
// cares can still point error_log at its own capture file via ini_set().
ini_set('error_log', '/dev/null');

/**
 * Hook-registration shims mirroring real WordPress' loose callback typing:
 * core's add_action()/add_filter() accept any mixed callback (including a
 * function name that is declared later in the same file, as Action Scheduler's
 * own loader does), so these shims store the callback unvalidated exactly like
 * core instead of rejecting it eagerly with a callable type-hint.
 */
function add_action(string $hook, mixed $callback, int $priority = 10, int $arguments = 1): bool
{
    $GLOBALS['lumeweb_cast_hooks'][] = ['action', $hook, $priority, $arguments, $callback];
    return true;
}

function add_filter(string $hook, mixed $callback, int $priority = 10, int $arguments = 1): bool
{
    $GLOBALS['lumeweb_cast_hooks'][] = ['filter', $hook, $priority, $arguments, $callback];
    return true;
}

function remove_action(string $hook, mixed $callback, int $priority = 10): bool
{
    $GLOBALS['lumeweb_cast_hooks'][] = ['remove_action', $hook, $priority];
    return true;
}

function remove_filter(string $hook, mixed $callback, int $priority = 10): bool
{
    $GLOBALS['lumeweb_cast_hooks'][] = ['remove_filter', $hook, $priority];
    return true;
}

function register_activation_hook(string $file, mixed $callback): void
{
    $GLOBALS['lumeweb_cast_lifecycle'][] = ['activate', $file, $callback];
}

function register_deactivation_hook(string $file, mixed $callback): void
{
    $GLOBALS['lumeweb_cast_lifecycle'][] = ['deactivate', $file, $callback];
}

function register_uninstall_hook(string $file, mixed $callback): void
{
    $GLOBALS['lumeweb_cast_lifecycle'][] = ['uninstall', $file, $callback];
}

/**
 * Minimal did_action()/doing_action() shims so the vendored Action Scheduler
 * loader (required by cast.php before boot) can run its version-registration
 * guards in the unit bootstrap. No action is ever "done" or "in progress" in
 * this harness (there is no do_action() shim), so both report their neutral
 * value, exactly like core on a request where those hooks never fired. Their
 * keyed signatures (returning int / bool) mirror core's real
 * did_action()/doing_action() rather than the stubs' inaccurate int|false
 * union, so PHPStan sees the shims' true return types.
 */
function did_action(string $hook_name): int
{
    return 0;
}

function doing_action(string $hook_name = null): bool
{
    return false;
}

/**
 * Minimal do_action() shim so production code can emit a named event (e.g. the
 * rearm-failure warning) without a full WordPress runtime. Registered callbacks
 * are intentionally NOT invoked — that would re-trigger arbitrary plugin hooks
 * such as Action Scheduler's `plugins_loaded` bootstrap during unrelated unit
 * tests — so it only records fired hooks for assertions, mirroring real core's
 * side-effect-free signal when nothing is wired to the hook.
 */
function do_action(string $hook_name, mixed ...$args): void
{
    $GLOBALS['lumeweb_cast_actions_fired'][$hook_name][] = $args;
}

/**
 * Standalone object-cache shims backed by a global, mirroring real WordPress'
 * wp_cache_* contract for the 'options' group: values are stored exactly as
 * core stores them (the maybe_serialize()'d form update_option() keeps, or the
 * raw row value get_option() primes the cache with on first read) and
 * wp_cache_get() reports a miss as false.
 *
 * The harness deliberately keeps these a standalone layer rather than wiring
 * get_option()/update_option() through them: several downstream suites seed
 * the option store directly across a test's lifetime and rely on read-through
 * behaviour (wiring the cache would leak stale entries between those reads).
 * The layer exists so a regression test can pin the production fix — the
 * persistence gateway's compare-and-set runs a raw conditional UPDATE that
 * bypasses update_option()'s cache refresh, so without the fix the 'options'
 * cache keeps serving the stale pre-CAS value.
 */
function wp_cache_get(string $key, string $group, bool $force = false): mixed
{
    return $GLOBALS['lumeweb_cast_option_cache'][$group][$key] ?? false;
}

function wp_cache_set(string $key, mixed $value, string $group, int $expire = 0): bool
{
    $GLOBALS['lumeweb_cast_option_cache'][$group][$key] = $value;

    return true;
}

function wp_cache_delete(string $key, string $group): bool
{
    if (!isset($GLOBALS['lumeweb_cast_option_cache'][$group][$key])) {
        return false;
    }

    unset($GLOBALS['lumeweb_cast_option_cache'][$group][$key]);

    return true;
}

/**
 * Compact maybe_serialize()/is_serialized()/maybe_unserialize() shims so the
 * object-cache layer stores and returns values exactly like core: arrays and
 * objects are serialize()'d, every other value is stored as-is. Core's
 * double-serialization of already-serialized values is intentionally omitted —
 * the harness only ever stores fresh values.
 */
function maybe_serialize(mixed $data): mixed
{
    return is_array($data) || is_object($data) ? serialize($data) : $data;
}

function is_serialized(mixed $data): bool
{
    if (!is_string($data) || $data === '') {
        return false;
    }

    // Only the documented serialized type tags (b, d, i, s, a, O, E, N) count;
    // a plain version string like "0.1.0" never matches.
    return (bool) preg_match('/^(?:[bidN]|a:\d+:\{|O:\d+:"|E:\d+:|s:\d+:")/', $data);
}

function maybe_unserialize(mixed $data): mixed
{
    if (!is_serialized($data)) {
        return $data;
    }

    /** @var mixed $value */
    $value = @unserialize($data);

    return $value === false ? $data : $value;
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

/**
 * Records every deletion so uninstall/cleanup paths can assert the exact set
 * of plugin-owned transients they removed.
 */
$GLOBALS['lumeweb_cast_transients_deleted'] = [];

function delete_transient(string $transient): bool
{
    $GLOBALS['lumeweb_cast_transients_deleted'][] = $transient;
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

/**
 * Minimal flush_rewrite_rules() shim recording each call so the permalink
 * guard / activation flush surface can assert exactly when a hard flush ran
 * (mirrors core's bool $hard = true default).
 */
function flush_rewrite_rules(bool $hard = true): void
{
    $GLOBALS['lumeweb_cast_rewrite_flushes'] = ($GLOBALS['lumeweb_cast_rewrite_flushes'] ?? 0) + 1;
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

/**
 * Minimal wp_parse_args() shim so the real WP_Admin_Bar::add_node() (preloaded
 * above) can merge node defaults without loading the full wp-includes
 * functions API. The admin-bar path only ever passes arrays (add_node()
 * normalizes objects first), so core's string query-string branch is
 * intentionally omitted here.
 *
 * @param array<string, mixed> $args
 * @param array<string, mixed> $defaults
 * @return array<string, mixed>
 */
function wp_parse_args(array $args, array $defaults = []): array
{
    return array_merge($defaults, $args);
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

$GLOBALS['lumeweb_cast_rest_routes'] = [];

/**
 * Records REST route registrations so the Publish REST route registrar can be
 * verified without loading the WP REST server. Mirrors register_rest_route()'s
 * (namespace, route, args[, override]) contract.
 *
 * @param array<string|int, mixed> $args
 */
function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
{
    $GLOBALS['lumeweb_cast_rest_routes'][] = [$namespace, $route, $args];

    return true;
}

$GLOBALS['lumeweb_cast_rest_root'] = 'http://example.test/wp-json/';

/**
 * Minimal rest_url() shim so admin asset localization can build real REST
 * endpoint URLs without loading the WP REST machinery. Mirrors core's
 * (path, scheme) contract; the scheme argument is accepted and ignored.
 */
function rest_url(string $path = '', ?string $scheme = null): string
{
    $root = rtrim($GLOBALS['lumeweb_cast_rest_root'], '/');

    return $root . '/' . ltrim($path, '/');
}

$GLOBALS['lumeweb_cast_get_posts_args'] = [];
$GLOBALS['lumeweb_cast_has_publishable_content'] = false;
$GLOBALS['lumeweb_cast_permalinks'] = [];
$GLOBALS['lumeweb_cast_public_post_types'] = [];

/**
 * Minimal get_permalink() shim driven by a scripted id→url map so the
 * WordPressDiscoverEnvironment adapter is testable without loading WP. Falls
 * back to Wordpress's plain-permalink shape (?p=ID) exactly like core does
 * when pretty permalinks are off. The shim always resolves a permalink (the
 * fallback mirrors core's plain-permalink shape), so — unlike real WordPress —
 * it never returns false.
 */
function get_permalink(int|object $post, bool $leavename = false): string
{
    $id = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;

    if (isset($GLOBALS['lumeweb_cast_permalinks'][$id])) {
        return (string) $GLOBALS['lumeweb_cast_permalinks'][$id];
    }

    return home_url('/?p=' . $id);
}

/**
 * Minimal get_post_types() shim returning whatever public custom post types the
 * test scripts into the global, so the discover adapter's published-content
 * keyset scopes over the site's own public CPTs without loading the full
 * taxonomy machinery.
 *
 * @param array<string, mixed> $args
 * @return list<string>
 */
function get_post_types(array $args = [], string $output = 'names'): array
{
    return $GLOBALS['lumeweb_cast_public_post_types'];
}

/**
 * Minimal get_posts() shim driven by a global so the publishable-content probe
 * is testable. `[123]` (an int post id) is returned when the global is true;
 * the recorded args let tests assert the probe queries at most one published
 * post/page (never a heavy full-site scan).
 *
 * @param array<string, mixed>|null $args
 * @return list<int>
 */
function get_posts(array $args = null): array
{
    $GLOBALS['lumeweb_cast_get_posts_args'] = $args ?? [];

    return ($GLOBALS['lumeweb_cast_has_publishable_content'] ?? false) ? [123] : [];
}

$GLOBALS['lumeweb_cast_home_url'] = 'http://example.test/';
$GLOBALS['lumeweb_cast_upload_dir'] = [];
$GLOBALS['lumeweb_cast_fs_writable'] = [];

/**
 * Minimal home_url() shim driven by a global so the WordPressProbeEnvironment
 * adapter is testable without loading WP. Appends the requested path and keeps
 * the canonical trailing slash, mirroring core's home_url('/') behaviour.
 */
function home_url(string $path = '', string $scheme = null): string
{
    $base = rtrim((string) ($GLOBALS['lumeweb_cast_home_url'] ?? ''), '/');

    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

/**
 * Minimal wp_upload_dir() shim returning whatever the test scripts into the
 * global, so the probe's uploads-writability check follows a trusted array
 * (basedir/error) exactly like core's upload_dir filter surface.
 *
 * @return array<string, mixed>
 */
function wp_upload_dir(): array
{
    return $GLOBALS['lumeweb_cast_upload_dir'];
}

/**
 * Minimal wp_is_writable() shim: delegates to the native check for the paths
 * the probe tests (a writable temp dir vs. a non-existent basedir).
 */
function wp_is_writable(string $path): bool
{
    return $GLOBALS['lumeweb_cast_fs_writable'][$path] ?? is_writable($path);
}

$GLOBALS['lumeweb_cast_wp_remote_calls'] = [];
$GLOBALS['lumeweb_cast_wp_remote_response'] = null;
$GLOBALS['lumeweb_cast_wp_remote_responses'] = [];
$GLOBALS['lumeweb_cast_environment_type'] = 'production';

/**
 * Minimal wp_remote_get() shim driven by a global scripted response array, so
 * the production {@see WordPressCaptureHttp} in the booted pipeline runs
 * against a controlled home page without any network. A per-URL response map
 * first (so a booted run can answer the home-page probe with HTML and the
 * discover sitemap fetch with XML from one suite), then the single default
 * response. Call args are recorded for acceptance assertions.
 *
 * @param array<string, mixed> $args
 * @return array{headers?: array<string, mixed>, body?: string, response?: array{code: int, message: string}, cookies?: array<mixed>, filename?: string|null}
 */
function wp_remote_get(string $url, array $args = []): array
{
    $GLOBALS['lumeweb_cast_wp_remote_calls'][] = ['url' => $url, 'args' => $args];

    $perUrl = $GLOBALS['lumeweb_cast_wp_remote_responses'][$url] ?? null;
    if (is_array($perUrl)) {
        return $perUrl;
    }

    $response = $GLOBALS['lumeweb_cast_wp_remote_response'];
    if (!is_array($response)) {
        return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    }

    return $response;
}

function wp_remote_retrieve_response_code(mixed $response): int
{
    if (!is_array($response)) {
        return 0;
    }

    $code = $response['response']['code'] ?? 0;

    return is_int($code) ? $code : 0;
}

/**
 * @return array<string, mixed>
 */
function wp_remote_retrieve_headers(mixed $response): array
{
    return is_array($response) && is_array($response['headers'] ?? null) ? $response['headers'] : [];
}

function wp_remote_retrieve_body(mixed $response): string
{
    return is_array($response) && is_string($response['body'] ?? null) ? $response['body'] : '';
}

function is_wp_error(mixed $thing): bool
{
    return false;
}

function wp_get_environment_type(): string
{
    return $GLOBALS['lumeweb_cast_environment_type'] ?? 'production';
}

$GLOBALS['lumeweb_cast_actions'] = [
    'actions' => [],
    'running' => [],
    'last_id' => 0,
    'available' => true,
];

/**
 * Minimal Action Scheduler shims backed by a global, so the booted production
 * composition (CastPlugin → WordPressActionScheduler →
 * WordPressActionSchedulerGateway over the real as_* 4.2.0 API) is exercisable
 * under test without loading the Action Scheduler library or its WP-Cron
 * runner.
 *
 * The shims mirror the real API's (group, hook, args) identity: a unique
 * schedule can only be queued once per slot, a tick's rearm cannot stack
 * duplicates, `running` slots report as in-flight (nextScheduled = true), and
 * the documented uninitialized boundary (0 / false / null / no-op) is honoured
 * while `available` is false. Action Scheduler's OWN WP-Cron runner semantics
 * are intentionally NOT shimmed here — those are exercised against the real
 * library at the WordPress integration boundary.
 */

/**
 * Canonical (group, hook, args) key for a shim slot.
 *
 * @param list<mixed> $args
 */
function lumeweb_cast_actions_key(string $hook, array $args, string $group): string
{
    return $group . '|' . $hook . '|' . serialize($args);
}

/**
 * Find the first slot matching hook/group (and args when not null), optionally
 * including running slots (hasScheduled). Returns the slot key or null.
 *
 * @param list<mixed>|null $args
 */
function lumeweb_cast_actions_find(string $hook, ?array $args, string $group, bool $includeRunning): ?string
{
    $store = $GLOBALS['lumeweb_cast_actions'];
    foreach ($store['actions'] as $key => $entry) {
        if ($entry['hook'] !== $hook || $entry['group'] !== $group) {
            continue;
        }
        if ($args !== null && $entry['args'] !== $args) {
            continue;
        }

        return $key;
    }

    if ($includeRunning) {
        foreach ($store['running'] as $key => $entry) {
            if ($entry['hook'] !== $hook || $entry['group'] !== $group) {
                continue;
            }
            if ($args !== null && $entry['args'] !== $args) {
                continue;
            }

            return $key;
        }
    }

    return null;
}

/**
 * @param list<mixed> $args
 */
function as_schedule_single_action(
    int $timestamp,
    string $hook,
    array $args = [],
    string $group = '',
    bool $unique = false,
    int $priority = 10
): int {
    $store = &$GLOBALS['lumeweb_cast_actions'];
    $key = lumeweb_cast_actions_key($hook, $args, $group);

    if (!$store['available']) {
        return 0;
    }

    // as_schedule_single_action(..., unique: true) refuses when another
    // pending or running action has the same hook/args/group.
    if ($unique && (isset($store['actions'][$key]) || isset($store['running'][$key]))) {
        return 0;
    }

    $store['actions'][$key] = [
        'timestamp' => $timestamp,
        'hook' => $hook,
        'args' => $args,
        'group' => $group,
        'id' => ++$store['last_id'],
    ];

    return $store['actions'][$key]['id'];
}

/**
 * @param list<mixed>|null $args
 */
function as_next_scheduled_action(string $hook = '', ?array $args = null, string $group = ''): int|bool
{
    $store = &$GLOBALS['lumeweb_cast_actions'];

    if (!$store['available']) {
        return false;
    }

    // A matching running action reports `true` (no timestamp is known until it
    // concludes); a matching pending action reports its scheduled timestamp.
    $match = lumeweb_cast_actions_find($hook, $args, $group, true);
    if ($match === null) {
        return false;
    }

    if (isset($store['running'][$match])) {
        return true;
    }

    return $store['actions'][$match]['timestamp'];
}

/**
 * @param list<mixed>|null $args
 */
function as_has_scheduled_action(string $hook = '', ?array $args = null, string $group = ''): bool
{
    $store = &$GLOBALS['lumeweb_cast_actions'];

    if (!$store['available']) {
        return false;
    }

    return lumeweb_cast_actions_find($hook, $args, $group, true) !== null;
}

/**
 * @param list<mixed> $args
 */
function as_unschedule_action(string $hook, array $args = [], string $group = ''): int|null
{
    $store = &$GLOBALS['lumeweb_cast_actions'];

    if (!$store['available']) {
        // The real as_unschedule_action() returns 0 when the runtime is
        // uninitialized, in tension with its documented int|null boundary.
        return 0;
    }

    $key = lumeweb_cast_actions_key($hook, $args, $group);
    if (!isset($store['actions'][$key])) {
        return null;
    }

    $id = $store['actions'][$key]['id'];
    unset($store['actions'][$key]);

    return $id;
}

/**
 * @param list<mixed> $args
 */
function as_unschedule_all_actions(string $hook, array $args = [], string $group = ''): void
{
    $store = &$GLOBALS['lumeweb_cast_actions'];

    if (!$store['available']) {
        return;
    }

    // as_unschedule_all_actions() only cancels *pending* matches (its
    // as_unschedule_action() loop queries STATUS_PENDING): a running action is
    // deliberately left to run to completion.
    $key = lumeweb_cast_actions_key($hook, $args, $group);
    unset($store['actions'][$key]);
}

$GLOBALS['lumeweb_cast_dbdelta_calls'] = [];

/**
 * Minimal dbDelta() shim for the custom-table install path.
 *
 * Records the CREATE TABLE DDL (the only query the Cast items-table installer
 * sends) so activation's schema install is assertable without the WordPress
 * upgrade machinery. Mirrors core's return contract: an array of delta results
 * (empty here, since no table physically changes under the shim).
 *
 * @param string|list<string> $queries
 * @return list<string>
 */
function dbDelta(string|array $queries = '', bool $execute = true): array
{
    foreach ((array) $queries as $query) {
        $GLOBALS['lumeweb_cast_dbdelta_calls'][] = (string) $query;
    }

    return [];
}

$GLOBALS['lumeweb_cast_wpdb_queries'] = [];
$GLOBALS['lumeweb_cast_wpdb_inserts'] = [];
$GLOBALS['lumeweb_cast_wpdb_rows'] = [];

/**
 * Default `$wpdb` surrogate so the WordPress persistence adapters
 * (WordPressWpDbGateway, WordPressCastExportItemsTable) never fatal in unit
 * tests that exercise them through the option/cron-style globals. Stands in
 * for the WordPress database global exactly like get_option() does for the
 * options table: same public surface the adapters use.
 *
 * Beyond recording every statement for acceptance assertions, the shim holds a
 * small in-memory items store (`lumeweb_cast_wpdb_rows`) mirroring the SQL
 * repository queue semantics: INSERT creates a queued row keyed by url_hash
 * (insertion order doubles as AUTO_INCREMENT id), the conditional claim only
 * advances a row still queued, status/retry/priority updates mutate that one
 * row, COUNT(*) is status-aware, and the claim peek returns the due
 * lowest-priority queued url_hash. That lets the booted CaptureStage run its
 * full claim → outcome → terminal-status cycle end to end without a live
 * database.
 */
$GLOBALS['wpdb'] = new class {
    public string $prefix = 'wptests_';

    public string $options = 'wptests_options';

    // Snake_case method names mirror the real wpdb API surface; PSR-1's
    // camel-caps rule intentionally exempts them.
    // phpcs:disable PSR1.Methods.CamelCapsMethodName

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function prepare(string $query, mixed ...$args): string
    {
        // Minimal faithful placeholder substitution so recorded queries read
        // like real MySQL: %i -> `identifier`, %s -> 'string', %d -> int.
        $out = '';
        $index = 0;
        foreach (preg_split('/(%%|%d|%s|%i)/', $query, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if ($part === '%i' || $part === '%s' || $part === '%d') {
                $value = $args[$index] ?? '';
                ++$index;
                $out .= match ($part) {
                    '%i' => '`' . (string) $value . '`',
                    '%d' => (string) (int) $value,
                    default => "'" . addslashes((string) $value) . "'",
                };
            } elseif ($part === '%%') {
                $out .= '%';
            } else {
                $out .= $part;
            }
        }

        return $out;
    }

    /**
     * Applies the repository's statement vocabulary to the row store: INSERT
     * creates a queued row (recording the statement for assertions), the
     * conditional claim, retry, priority and terminal-status updates mutate the
     * matching row, and every other statement (e.g. DROP IF EXISTS) is a no-op.
     */
    public function query(string $query): int
    {
        $GLOBALS['lumeweb_cast_wpdb_queries'][] = $query;

        if (str_starts_with(strtoupper(ltrim($query)), 'INSERT')) {
            $GLOBALS['lumeweb_cast_wpdb_inserts'][] = $query;

            $row = $this->parseInsert($query);
            if ($row === null) {
                // Not one of the repository's inserts (unparseable here): keep
                // the legacy "one affected row" answer so older assertions hold.
                return 1;
            }

            return $this->insertRow($row);
        }

        if (str_starts_with(strtoupper(ltrim($query)), 'UPDATE')) {
            return $this->applyUpdate($query);
        }

        return 0;
    }

    public function get_var(string $query, int $x = 0, int $y = 0): mixed
    {
        $GLOBALS['lumeweb_cast_wpdb_queries'][] = $query;

        if (str_contains($query, 'SELECT url_hash FROM')) {
            return $this->nextQueuedHash($query);
        }

        if (str_contains($query, 'SELECT priority FROM')) {
            $row = $this->rowByHashForQuery($query);

            return $row === null ? null : $row['priority'];
        }

        if (str_contains($query, 'SELECT fetch_attempts FROM')) {
            $row = $this->rowByHashForQuery($query);

            return $row === null ? null : $row['fetch_attempts'];
        }

        if (str_contains($query, 'COUNT(*)')) {
            // Status-aware totals: the queued-only count the discover
            // acceptance reads as enqueued and the queued+processing count the
            // capture fixed point reads as pending.
            return $this->countRows($query);
        }

        return null;
    }

    public function get_row(string $query, string $output = 'OBJECT', int $y = 0): mixed
    {
        $GLOBALS['lumeweb_cast_wpdb_queries'][] = $query;

        // The rewrite stage's first-rewritable peek (`WHERE status = %s AND
        // (kind IN ... OR kind = %s AND ... path`) has no url_hash guard, so
        // resolve it PHP-side like the claim select, lowest priority first.
        $row = str_contains($query, 'WHERE status = ')
            ? $this->firstRewritableRow()
            : $this->rowByHashForQuery($query);
        if ($row === null) {
            return null;
        }

        if (strtoupper($output) === 'ARRAY_A') {
            return $row;
        }

        $object = new \stdClass();
        foreach ($row as $key => $value) {
            $object->{$key} = $value;
        }

        return $object;
    }

    /**
     * First done row the rewrite stage may claim: a page/redirect/text row, or
     * an asset whose extension is text-capable (css/js/mjs/json/xml/rss/atom),
     * lowest numeric priority first — mirroring the SQL rewritability predicate
     * the repository pushes down and the fake gateway's contract.
     *
     * @return array<string, mixed>|null
     */
    private function firstRewritableRow(): ?array
    {
        $candidates = [];
        foreach ($this->rows() as $row) {
            if (($row['status'] ?? null) !== 'done') {
                continue;
            }
            if (!$this->isRewritableRow($row)) {
                continue;
            }
            $candidates[] = $row;
        }
        usort($candidates, static fn (array $a, array $b): int => ($a['priority'] ?? 0) <=> ($b['priority'] ?? 0));

        return $candidates === [] ? null : $candidates[0];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isRewritableRow(array $row): bool
    {
        $kind = (string) ($row['kind'] ?? '');
        if (in_array($kind, ['page', 'redirect', 'text'], true)) {
            return true;
        }

        if ($kind !== 'asset') {
            return false;
        }

        $extension = strtolower((string) strrchr((string) ($row['path'] ?? ''), '.'));
        if ($extension !== '' && $extension[0] === '.') {
            $extension = substr($extension, 1);
        }

        return in_array($extension, ['css', 'js', 'mjs', 'json', 'xml', 'rss', 'atom'], true);
    }

    /**
     * Return whatever rows the scripted global holds (consuming them), so the
     * WordPressDiscoverEnvironment keyset sees the fixture's published IDs.
     *
     * @return list<\stdClass>
     */
    public function get_results(string $query, string $output = 'OBJECT'): array
    {
        $GLOBALS['lumeweb_cast_wpdb_queries'][] = $query;

        $rows = $GLOBALS['lumeweb_cast_wpdb_results'] ?? [];
        $GLOBALS['lumeweb_cast_wpdb_results'] = [];

        return $rows;
    }

    /**
     * Parse a fully-prepared INSERT (repository column order is fixed) into a
     * row shape, or null for a statement the shim cannot interpret.
     *
     * @return array<string, mixed>|null
     */
    private function parseInsert(string $query): ?array
    {
        if (preg_match('/VALUES\s+\((.+)\)$/is', $query, $match) !== 1) {
            return null;
        }

        $columns = ['url_hash', 'url', 'identity', 'path', 'kind', 'priority', 'status', 'fetch_attempts', 'retry_at'];
        $values = $this->splitSqlValues($match[1]);
        if (count($values) !== count($columns)) {
            return null;
        }

        $row = [];
        foreach ($columns as $position => $column) {
            $value = $this->unquote($values[$position]);
            $row[$column] = $column === 'priority' || $column === 'fetch_attempts' || $column === 'retry_at'
                ? (int) $value
                : $value;
        }

        return $row;
    }

    /**
     * Split a SQL VALUES group into its comma-separated literals, honouring
     * single-quoted strings and backslash-escaped characters inside them.
     *
     * @return list<string>
     */
    private function splitSqlValues(string $group): array
    {
        $parts = [];
        $buffer = '';
        $quoted = false;
        $length = strlen($group);
        for ($i = 0; $i < $length; ++$i) {
            $char = $group[$i];
            if ($char === '\\' && $quoted && $i + 1 < $length) {
                $buffer .= $char . $group[$i + 1];
                ++$i;
                continue;
            }
            if ($char === "'") {
                if ($quoted && $i + 1 < $length && $group[$i + 1] === "'") {
                    $buffer .= "''";
                    ++$i;
                    continue;
                }
                $quoted = !$quoted;
                $buffer .= $char;
                continue;
            }
            if ($char === ',' && !$quoted) {
                $parts[] = trim($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }

        $parts[] = trim($buffer);

        return $parts;
    }

    private function unquote(string $value): string
    {
        $trimmed = trim($value);
        if (strlen($trimmed) >= 2 && $trimmed[0] === "'" && $trimmed[strlen($trimmed) - 1] === "'") {
            return str_replace(["\\'", "''"], "'", substr($trimmed, 1, -1));
        }

        return $trimmed;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insertRow(array $row): int
    {
        $rows = $this->rows();
        foreach ($rows as $existing) {
            if (($existing['url_hash'] ?? null) === ($row['url_hash'] ?? null)) {
                return 0;
            }
        }

        $rows[] = $row;
        $this->setRows($rows);

        return 1;
    }

    private function applyUpdate(string $query): int
    {
        // Atomic conditional claim: only a row still queued becomes processing
        // and its attempt count advances exactly once.
        if (str_contains($query, 'fetch_attempts = fetch_attempts + 1')) {
            return $this->claimByHash($this->hashFromWhere($query));
        }

        if (preg_match("/SET status = '([^']+)', retry_at = (\d+) WHERE url_hash = '([^']+)'/", $query, $match) === 1) {
            return $this->mutateRow($match[3], ['status' => $match[1], 'retry_at' => (int) $match[2]]);
        }

        if (preg_match("/SET priority = (\d+) WHERE url_hash = '([^']+)'/", $query, $match) === 1) {
            return $this->mutateRow($match[2], ['priority' => (int) $match[1]]);
        }

        if (preg_match("/SET status = '([^']+)' WHERE url_hash = '([^']+)'/", $query, $match) === 1) {
            return $this->mutateRow($match[2], ['status' => $match[1]]);
        }

        return 0;
    }

    private function claimByHash(?string $hash): int
    {
        if ($hash === null) {
            return 0;
        }

        $rows = $this->rows();
        foreach ($rows as $index => $row) {
            if (($row['url_hash'] ?? null) !== $hash) {
                continue;
            }
            if (($row['status'] ?? null) !== 'queued') {
                return 0;
            }

            $rows[$index]['status'] = 'processing';
            $rows[$index]['fetch_attempts'] = (int) ($rows[$index]['fetch_attempts'] ?? 0) + 1;
            $this->setRows($rows);

            return 1;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function mutateRow(string $hash, array $changes): int
    {
        $rows = $this->rows();
        foreach ($rows as $index => $row) {
            if (($row['url_hash'] ?? null) !== $hash) {
                continue;
            }

            foreach ($changes as $key => $value) {
                $rows[$index][$key] = $value;
            }
            $this->setRows($rows);

            return 1;
        }

        return 0;
    }

    private function hashFromWhere(string $query): ?string
    {
        if (preg_match("/WHERE url_hash = '([^']+)'/", $query, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowByHashForQuery(string $query): ?array
    {
        $hash = $this->hashFromWhere($query);
        if ($hash === null) {
            return null;
        }

        foreach ($this->rows() as $row) {
            if (($row['url_hash'] ?? null) === $hash) {
                return $row;
            }
        }

        return null;
    }

    private function nextQueuedHash(string $query): ?string
    {
        preg_match('/retry_at <= (\d+)/', $query, $match);
        $now = isset($match[1]) ? (int) $match[1] : PHP_INT_MAX;

        $best = null;
        $bestPriority = null;
        foreach ($this->rows() as $row) {
            if (($row['status'] ?? null) !== 'queued' || (int) ($row['retry_at'] ?? 0) > $now) {
                continue;
            }

            $priority = (int) ($row['priority'] ?? 0);
            if ($best === null || $priority < $bestPriority) {
                $best = (string) $row['url_hash'];
                $bestPriority = $priority;
            }
        }

        return $best;
    }

    private function countRows(string $query): int
    {
        $count = 0;
        $pending = str_contains($query, 'status IN ');
        foreach ($this->rows() as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($pending) {
                if ($status === 'queued' || $status === 'processing') {
                    ++$count;
                }
                continue;
            }

            if (preg_match("/WHERE status = '([^']+)'/", $query, $match) === 1 && $status === $match[1]) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $GLOBALS['lumeweb_cast_wpdb_rows'] ?? [];

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function setRows(array $rows): void
    {
        $GLOBALS['lumeweb_cast_wpdb_rows'] = $rows;
    }

    // phpcs:enable PSR1.Methods.CamelCapsMethodName
};
