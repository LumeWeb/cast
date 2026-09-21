<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit;

use LumeWeb\Cast\CastPlugin;
use LumeWeb\Cast\Jobs\ContentPublishScheduler;
use LumeWeb\Cast\Jobs\JobsHookSubscriber;
use LumeWeb\Cast\Jobs\PublishIdentity;
use LumeWeb\Cast\Jobs\RetentionScheduler;
use LumeWeb\Cast\Jobs\WordPressIdentityGateway;
use LumeWeb\Cast\Persistence\WordPressOptionGateway;
use LumeWeb\Cast\Publish\WordPressPublishRegistry;
use LumeWeb\Cast\Uninstall;
use PHPUnit\Framework\TestCase;

final class CastPluginTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_hooks'] = [];
        $GLOBALS['lumeweb_cast_lifecycle'] = [];
        $GLOBALS['lumeweb_cast_options'] = [];
        // Each test boots as if a fresh WordPress request arrived; boot is
        // idempotent within a process, so re-arm the guard between tests.
        CastPlugin::resetBootIdempotence();
    }

    public function testBootRegistersLifecycleWithoutMutatingContent(): void
    {
        CastPlugin::boot('/plugins/cast/cast.php');

        // Regression: a booted plugin must never register a filter of any kind
        // or a front-end rendering hook that could rewrite public output
        // (e.g. the_content). Only actions — admin entrypoints, WP-Cron tick /
        // follow-up and the save-time transition hook — are registered.
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

    public function testBootRegistersTheBackgroundJobHookActions(): void
    {
        CastPlugin::boot('/plugins/cast/cast.php');

        $hooks = array_column($GLOBALS['lumeweb_cast_hooks'], 1);

        self::assertContains(ContentPublishScheduler::AUTO_HOOK, $hooks);
        self::assertContains(ContentPublishScheduler::FOLLOW_UP_HOOK, $hooks);
        self::assertContains(JobsHookSubscriber::TRANSITION_HOOK, $hooks);
    }

    public function testBootRegistersTheArtifactRetentionHookAction(): void
    {
        CastPlugin::boot('/plugins/cast/cast.php');

        $hooks = array_column($GLOBALS['lumeweb_cast_hooks'], 1);

        // The retention GC is composed in the booted job loop: the WP-Cron
        // hook is registered as an action (never a front-end filter), so a
        // terminal run / periodic sweep can fire it.
        self::assertContains(RetentionScheduler::RETENTION_HOOK, $hooks);
    }

    /**
     * Boot coverage for the identity persistence wiring: after a real boot, a
     * publish-registry write through the WordPress option gateway is visible
     * through the identity gateway reading the same non-autoloaded option —
     * and only once both identity halves are recorded. This is the production
     * option store the booted composition wires both adapters onto, and it keeps
     * a partial write from ever surfacing as a ready identity for auto-exports.
     */
    public function testBootedOptionStoreSharesRegistryWritesWithTheIdentityGateway(): void
    {
        CastPlugin::boot('/plugins/cast/cast.php');

        $options = new WordPressOptionGateway();
        $registry = new WordPressPublishRegistry($options);
        $identity = new WordPressIdentityGateway($options);

        // A website-only partial record must not expose a ready identity.
        $registry->recordWebsite('website-1', 'Example');
        self::assertFalse($identity->hasIdentity());

        // Completing the IPNS half makes the same store report the full, ready
        // identity round-tripped through PublishIdentity conventions.
        $registry->recordIpnsKey('Key One', 'key-1');
        self::assertTrue($identity->hasIdentity());

        $current = $identity->current();
        self::assertNotNull($current);
        self::assertSame('website-1', $current->websiteId);
        self::assertSame('Example', $current->websiteName);
        self::assertSame('key-1', $current->ipnsKeyId);
        self::assertSame('Key One', $current->ipnsKeyName);
        self::assertSame(PublishIdentity::SCHEMA_VERSION, $GLOBALS['lumeweb_cast_options'][WordPressPublishRegistry::OPTION_KEY]['schema_version']);
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
