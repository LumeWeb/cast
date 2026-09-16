<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Smoke tests that prove the integration harness actually boots a real,
 * database-backed WordPress 7.1 install and that the Cast plugin composes
 * against it without error. This is the "plugin boots under a real WordPress
 * install" test the harness was set up to enable.
 */
final class BootTest extends TestCase
{
    public function testWordPressAndDatabaseAreReachable(): void
    {
        // WordPress core booted from ABSPATH (the pinned 7.1 core) using our
        // env-driven config: WP_TESTS_TITLE proves the test config (not a
        // production one) took effect.
        $this->assertSame('Test Blog', get_bloginfo('name'));

        // Prove real DB connectivity end to end through wpdb (unit tests mock
        // this via shims; here we use the live MariaDB provisioned by compose).
        global $wpdb;
        $this->assertInstanceOf(\wpdb::class, $wpdb);
        $this->assertSame(1, (int) $wpdb->get_var('SELECT 1'));

        // The test schema was created inside the MariaDB database, so tables
        // WP needs for boot (options) actually exist.
        $optionsTable = $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE option_name = %s', $wpdb->options, 'siteurl')
        );
        $this->assertSame(1, (int) $optionsTable);
    }

    public function testPluginBootsUnderRealWordPress(): void
    {
        // cast.php refuses to boot if ABSPATH is missing (real WP is loaded, so
        // it proceeds) and must not fatal while composing the plugin.
        require_once dirname(__DIR__, 2) . '/cast.php';

        $this->assertTrue(class_exists(\LumeWeb\Cast\CastPlugin::class));

        // The plugin's admin subscriber registered the hooks core needs to
        // serve the onboarding screen (admin_menu + admin_enqueue_scripts).
        $this->assertNotFalse(has_action('admin_enqueue_scripts'));
        $this->assertNotFalse(has_action('admin_menu'));
    }
}
