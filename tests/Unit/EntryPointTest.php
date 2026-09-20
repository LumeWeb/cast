<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit;

use LumeWeb\Cast\CastPlugin;
use LumeWeb\Cast\Jobs\ContentPublishScheduler;
use LumeWeb\Cast\Jobs\JobsHookSubscriber;
use LumeWeb\Cast\Uninstall;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class EntryPointTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_hooks'] = [];
        $GLOBALS['lumeweb_cast_lifecycle'] = [];
        // Each test includes the entry point as if in a fresh WordPress
        // request; boot is idempotent within a process, so re-arm the guard.
        CastPlugin::resetBootIdempotence();
    }

    public function testIncludingEntryPointBootsLifecycleWithoutMutatingContent(): void
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/srv/www/');
        }

        include dirname(__DIR__, 2) . '/cast.php';

        // Regression: the included entry point must not register any filter or
        // front-end rendering hook that could rewrite public output. Only
        // actions — admin onboarding, WP-Cron tick / follow-up and the
        // save-time transition hook — are expected.
        self::assertSame([], $this->registeredFilterHooks());
        self::assertSame([], $this->contentRenderingHooks());
        self::assertSame(
            ['activate', 'deactivate', 'uninstall'],
            array_column($GLOBALS['lumeweb_cast_lifecycle'], 0),
        );
        self::assertSame(
            [Uninstall::class, 'uninstall'],
            $GLOBALS['lumeweb_cast_lifecycle'][2][2],
        );
    }

    public function testIncludingEntryPointRegistersBackgroundJobHookActions(): void
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/srv/www/');
        }

        $GLOBALS['lumeweb_cast_hooks'] = [];
        include dirname(__DIR__, 2) . '/cast.php';

        $hooks = array_column($GLOBALS['lumeweb_cast_hooks'], 1);

        self::assertContains(ContentPublishScheduler::AUTO_HOOK, $hooks);
        self::assertContains(ContentPublishScheduler::FOLLOW_UP_HOOK, $hooks);
        self::assertContains(JobsHookSubscriber::TRANSITION_HOOK, $hooks);
    }

    /**
     * Runs in a fresh process so the cast.php include here is the FIRST include
     * of the Action Scheduler loader in that process (require_once makes later
     * includes no-ops in a shared process). That guarantees the version-registry
     * hooks recorded below were made by cast.php itself, before Cast boot.
     */
    #[RunInSeparateProcess]
    public function testIncludingEntryPointLoadsActionSchedulerBeforeBoot(): void
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/srv/www/');
        }

        // The real vendored woocommerce/action-scheduler plugin file must be
        // required (its version-registry entry loads) BEFORE CastPlugin::boot
        // composes the WordPressActionScheduler adapter — Action Scheduler is the
        // sole scheduling runtime and Cast must never fall back to vanilla
        // WP-Cron single-event scheduling.
        self::assertFalse(
            class_exists('ActionScheduler_Versions', false),
            'The fresh process must start without Action Scheduler loaded.',
        );
        $GLOBALS['lumeweb_cast_hooks'] = [];
        include dirname(__DIR__, 2) . '/cast.php';

        self::assertTrue(
            class_exists('ActionScheduler_Versions', false),
            'Including the entry point must load the vendored Action Scheduler library.',
        );
        $pluginsLoaded = array_values(array_filter(
            $GLOBALS['lumeweb_cast_hooks'],
            static fn (array $entry): bool => ($entry[0] ?? null) === 'action'
                && ($entry[1] ?? null) === 'plugins_loaded',
        ));
        self::assertNotSame([], $pluginsLoaded, 'The Action Scheduler loader must register on plugins_loaded.');
    }

    /**
     * @return list<string>
     */
    private function registeredFilterHooks(): array
    {
        return array_values(array_filter(
            array_column($GLOBALS['lumeweb_cast_hooks'], 0, 1),
            static fn (string $type): bool => $type === 'filter',
        ));
    }

    /**
     * Hooks that fire while rendering public pages and could rewrite output.
     *
     * @return list<string>
     */
    private function contentRenderingHooks(): array
    {
        $rendering = [
            'the_content',
            'the_excerpt',
            'the_title',
            'get_the_excerpt',
            'wp_head',
            'wp_footer',
            'loop_start',
            'loop_end',
        ];

        return array_values(array_intersect(
            $rendering,
            array_column($GLOBALS['lumeweb_cast_hooks'], 1),
        ));
    }
}
