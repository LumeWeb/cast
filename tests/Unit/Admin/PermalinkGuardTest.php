<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use ComposePress\Core\Testing\RecordingHooks;
use LumeWeb\Cast\Admin\PermalinkGuard;
use LumeWeb\Cast\Export\ProbeStage;
use PHPUnit\Framework\TestCase;

/**
 * The permalink-structure guard is the thin WordPress controller that keeps
 * Cast-ready sites on pretty permalinks: it forces a plain structure to the
 * canonical 'Day and name' value on admin_init (hard-flushing rewrite rules
 * exactly once), never overwrites a non-empty custom structure, and shows a
 * dismissible notice with a 'Use Day and name' action when a custom structure
 * is in place. These tests pin the subscription surface (admin_init /
 * admin_notices / the two admin-post handlers) and every enforcement/flush/
 * notice/apply/dismiss decision through the fakes.
 */
final class PermalinkGuardTest extends TestCase
{
    private FakeRequestContext $context;
    private FakePermalinkSettings $settings;
    private FakePermalinkNoticeDismissalStore $dismissals;
    private PermalinkGuard $guard;

    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_options'] = [];
        $GLOBALS['lumeweb_cast_rewrite_flushes'] = 0;

        $this->context = new FakeRequestContext();
        $this->context->screenId = 'dashboard';
        $this->settings = new FakePermalinkSettings();
        $this->dismissals = new FakePermalinkNoticeDismissalStore();
        $this->guard = new PermalinkGuard($this->context, $this->settings, $this->dismissals);
    }

    private function canonical(): string
    {
        return ProbeStage::CANONICAL_PERMALINK_STRUCTURE;
    }

    public function testSubscribeRegistersEnforcementNoticeAndPostHandlersOnly(): void
    {
        $hooks = new RecordingHooks();
        $hooks->reset();

        $this->guard->subscribe($hooks);

        self::assertSame(
            [
                'admin_init',
                'admin_notices',
                'admin_post_' . PermalinkGuard::ACTION_USE_CANONICAL,
                'admin_post_' . PermalinkGuard::NOTICE_DISMISS_ACTION,
            ],
            $hooks->actionNames(),
        );

        self::assertCount(1, $hooks->actionsFor('admin_init'));
        self::assertCount(1, $hooks->actionsFor('admin_notices'));
        self::assertCount(1, $hooks->actionsFor('admin_post_' . PermalinkGuard::ACTION_USE_CANONICAL));
        self::assertCount(1, $hooks->actionsFor('admin_post_' . PermalinkGuard::NOTICE_DISMISS_ACTION));
    }

    public function testEnforceForcesPlainPermalinksToCanonicalAndFlushesOnce(): void
    {
        $this->settings->structure = '';

        $this->guard->enforceCanonicalStructure();

        self::assertSame($this->canonical(), $this->settings->structure);
        self::assertSame(1, $this->settings->flushCount);
        self::assertTrue($this->settings->flushed);
        self::assertSame([$this->canonical()], $this->settings->writes);
    }

    public function testEnforceDoesNotFlushTwiceAcrossRequests(): void
    {
        // First enforcement flushes and flags; a later request where the
        // structure is somehow plain again re-enforces WITHOUT re-flushing.
        $this->settings->structure = '';
        $this->guard->enforceCanonicalStructure();

        $this->settings->structure = '';
        $this->guard->enforceCanonicalStructure();

        self::assertSame($this->canonical(), $this->settings->structure);
        self::assertSame(1, $this->settings->flushCount, 'the hard flush must run exactly once');
    }

    public function testEnforceNeverOverwritesANonEmptyCustomStructure(): void
    {
        $this->settings->structure = '/%custom%/';

        $this->guard->enforceCanonicalStructure();

        self::assertSame('/%custom%/', $this->settings->structure);
        self::assertSame([], $this->settings->writes);
        self::assertSame(0, $this->settings->flushCount);
    }

    public function testEnforceLeavesTheCanonicalStructureUntouched(): void
    {
        $this->settings->structure = $this->canonical();

        $this->guard->enforceCanonicalStructure();

        self::assertSame($this->canonical(), $this->settings->structure);
        self::assertSame([], $this->settings->writes);
        self::assertSame(0, $this->settings->flushCount);
    }

    public function testEnforceSkipsWithoutCapability(): void
    {
        $this->context->allowed = false;
        $this->settings->structure = '';

        $this->guard->enforceCanonicalStructure();

        self::assertSame('', $this->settings->structure);
        self::assertSame(0, $this->settings->flushCount);
    }

    public function testRenderNoticeShowsForACustomStructureWithTheFixButton(): void
    {
        $this->settings->structure = '/%custom%/';

        ob_start();
        $this->guard->renderNotice();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('cast-permalink-notice', $output);
        // The template escapes the quotes (esc_html), so the assertion pins the
        // escaped form the browser actually receives.
        self::assertStringContainsString('Cast needs &quot;Day and name&quot; permalinks', $output);
        self::assertStringContainsString('Use Day and name', $output);
        self::assertStringContainsString('name="action"', $output);
        self::assertStringContainsString(PermalinkGuard::ACTION_USE_CANONICAL, $output);
        self::assertStringContainsString(PermalinkGuard::NOTICE_DISMISS_ACTION, $output);
        self::assertStringContainsString('http://example.test/wp-admin/admin-post.php', $output);
    }

    public function testRenderNoticeSkipsForCanonicalPlainAndDismissed(): void
    {
        $cases = [
            'canonical' => $this->canonical(),
            'plain' => '',
        ];
        foreach ($cases as $label => $structure) {
            $this->settings->structure = $structure;

            ob_start();
            $this->guard->renderNotice();
            self::assertSame('', (string) ob_get_clean(), "no notice for {$label} structure");
        }

        $this->settings->structure = '/%custom%/';
        $this->dismissals->dismissed = true;

        ob_start();
        $this->guard->renderNotice();
        self::assertSame('', (string) ob_get_clean(), 'no notice once the current user dismissed it');
    }

    public function testRenderNoticeSkipsWithoutCapability(): void
    {
        $this->context->allowed = false;
        $this->settings->structure = '/%custom%/';

        ob_start();
        $this->guard->renderNotice();

        self::assertSame('', (string) ob_get_clean());
    }

    public function testApplyCanonicalDeniesWithoutNonce(): void
    {
        $this->context->nonceValid = false;
        $this->settings->structure = '/%custom%/';

        $this->guard->applyCanonical();

        self::assertSame('/%custom%/', $this->settings->structure);
        self::assertSame(0, $this->settings->flushCount);
        self::assertSame(1, $this->context->denyCount);
    }

    public function testApplyCanonicalDeniesWithoutCapability(): void
    {
        $this->context->allowed = false;
        $this->settings->structure = '/%custom%/';

        $this->guard->applyCanonical();

        self::assertSame('/%custom%/', $this->settings->structure);
        self::assertSame(1, $this->context->denyCount);
    }

    public function testApplyCanonicalReplacesCustomAndFlushesImmediately(): void
    {
        $this->settings->structure = '/%custom%/';

        $this->guard->applyCanonical();

        self::assertSame($this->canonical(), $this->settings->structure);
        self::assertSame(1, $this->settings->flushCount, 'the explicit apply always flushes');
        self::assertSame(0, $this->context->denyCount);
        self::assertSame(['back:' . admin_url('options-permalink.php')], $this->context->redirects);
    }

    public function testDismissNoticePersistsDismissalAndRedirects(): void
    {
        $this->settings->structure = '/%custom%/';

        $this->guard->dismissNotice();

        self::assertTrue($this->dismissals->dismissed);
        self::assertSame(1, $this->dismissals->dismissCount);
        self::assertSame(['back:' . admin_url('options-permalink.php')], $this->context->redirects);
        self::assertSame('/%custom%/', $this->settings->structure, 'dismissal must never touch the structure');
    }

    public function testDismissNoticeDeniesWithoutNonceOrCapability(): void
    {
        $this->context->nonceValid = false;
        $this->guard->dismissNotice();
        self::assertSame(1, $this->context->denyCount);
        self::assertFalse($this->dismissals->dismissed);

        $this->context->nonceValid = true;
        $this->context->allowed = false;
        $this->guard->dismissNotice();
        self::assertSame(2, $this->context->denyCount);
        self::assertFalse($this->dismissals->dismissed);
    }
}
