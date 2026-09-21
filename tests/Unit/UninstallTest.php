<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit;

use LumeWeb\Cast\Admin\PortalConnectionResolver;
use LumeWeb\Cast\Uninstall;
use PHPUnit\Framework\TestCase;

final class UninstallTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_options'] = [];
        $GLOBALS['lumeweb_cast_network_options'] = [];
    }

    public function testUninstallRemovesPersistedOption(): void
    {
        $GLOBALS['lumeweb_cast_options']['cast_version'] = '0.1.0';

        Uninstall::uninstall();

        self::assertSame([], $GLOBALS['lumeweb_cast_options']);
    }

    public function testUninstallRemovesGlobalOnboardingOption(): void
    {
        $GLOBALS['lumeweb_cast_options']['cast_onboarding'] = ['schema_version' => 1];

        Uninstall::uninstall();

        self::assertArrayNotHasKey('cast_onboarding', $GLOBALS['lumeweb_cast_options']);
    }

    public function testUninstallRemovesPersistedNetworkOption(): void
    {
        $GLOBALS['lumeweb_cast_network_options']['cast_version'] = '0.1.0';

        Uninstall::uninstall();

        self::assertSame([], $GLOBALS['lumeweb_cast_network_options']);
    }

    public function testUninstallRemovesPolicyAndGuardOptions(): void
    {
        // These options owned by the publish/export surface previously
        // survived uninstall; the permalink flush flag is the one that
        // misbehaves — a fresh reinstall would skip its one-time hard flush
        // and leave plain permalinks unflushed. The others are plain residue.
        $GLOBALS['lumeweb_cast_options']['cast_permalink_structure_flushed'] = true;
        $GLOBALS['lumeweb_cast_options']['cast_publish_retention_days'] = 7;
        $GLOBALS['lumeweb_cast_options']['cast_artifact_validation'] = 'strict';

        Uninstall::uninstall();

        self::assertSame([], $GLOBALS['lumeweb_cast_options']);
    }

    public function testUninstallRemovesTheConnectionMemoTransient(): void
    {
        $GLOBALS['lumeweb_cast_transients_deleted'] = [];

        Uninstall::uninstall();

        self::assertContains(
            PortalConnectionResolver::CACHE_KEY,
            $GLOBALS['lumeweb_cast_transients_deleted'],
            'uninstall must remove the cross-request connection memo transient',
        );
    }

    public function testUninstallSweepsLockLeaseRowsByPrefix(): void
    {
        $GLOBALS['lumeweb_cast_wpdb_queries'] = [];

        Uninstall::uninstall();

        // esc_like escapes the prefix underscores before the statement is
        // prepared, so matching on the bare word 'lease' stays requiring no
        // shim-specific escape knowledge.
        $sweeps = array_values(array_filter(
            $GLOBALS['lumeweb_cast_wpdb_queries'],
            static fn (string $query): bool => str_contains($query, 'lease'),
        ));
        self::assertNotSame([], $sweeps, 'uninstall must sweep the cast_lease_* option rows');
        $sweep = $sweeps[0];
        self::assertStringStartsWith('DELETE FROM', $sweep);
        self::assertStringContainsString('LIKE', $sweep);
    }

    public function testUninstallDropsTheExportItemsTable(): void
    {
        $GLOBALS['lumeweb_cast_wpdb_queries'] = [];

        Uninstall::uninstall();

        // The lease sweep runs before the drop, so the drop statement is
        // located rather than pinned to index 0.
        $drops = array_values(array_filter(
            $GLOBALS['lumeweb_cast_wpdb_queries'],
            static fn (string $query): bool => str_contains($query, 'DROP TABLE IF EXISTS'),
        ));
        self::assertNotSame(
            [],
            $drops,
            'uninstall must drop the items table',
        );
        self::assertStringContainsString(
            'wptests_cast_export_items',
            $drops[0],
            'the drop must target the plugin-owned items table',
        );
    }
}
