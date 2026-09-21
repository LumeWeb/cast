<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use ComposePress\Core\Testing\RecordingHooks;
use LumeWeb\Cast\Admin\DomainRestRouteRegistrar;
use LumeWeb\Cast\Admin\DomainSetupService;
use LumeWeb\Cast\Admin\NoticeDismissalStore;
use LumeWeb\Cast\Admin\PublishAdminSubscriber;
use LumeWeb\Cast\Admin\PublishRestRouteRegistrar;
use LumeWeb\Cast\Admin\PublishSetupService;
use LumeWeb\Cast\Environment\EnvIdentity;
use LumeWeb\Cast\Environment\EnvReader;
use LumeWeb\Cast\Export\InMemoryRunRepository;
use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Jobs\ContentPublishScheduler;
use LumeWeb\Cast\Jobs\FixedClock;
use LumeWeb\Cast\Jobs\InMemoryIdentityGateway;
use LumeWeb\Cast\Jobs\InMemoryPublishModeStore;
use LumeWeb\Cast\Jobs\InMemoryScheduler;
use LumeWeb\Cast\Jobs\PublishIdentity;
use LumeWeb\Cast\Jobs\PublishMode;
use LumeWeb\Cast\Jobs\TickConfig;
use LumeWeb\Cast\Onboarding\Wizard;
use LumeWeb\Cast\Onboarding\WizardState;
use LumeWeb\Cast\Publish\Domain;
use LumeWeb\Cast\Tests\Unit\Onboarding\FakeWizardStore;
use PHPUnit\Framework\TestCase;

/**
 * The publish admin subscriber is the thin WordPress controller that surfaces
 * the publish UX: a top-level menu page guarded by the manage_options
 * capability, the admin styles loaded only on that page for users who may
 * manage options, and an admin-bar node pointing at the page. These tests pin
 * the subscription surface (only admin_menu / admin_enqueue_scripts /
 * admin_bar_menu) and the capability + screen checks, so the subscriber never
 * registers hooks outside the admin or serves assets to unauthorized users.
 */
final class PublishAdminSubscriberTest extends TestCase
{
    private FakeRequestContext $context;
    private PublishAdminSubscriber $subscriber;

    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_menu_pages'] = [];
        $GLOBALS['lumeweb_cast_enqueued_scripts'] = [];
        $GLOBALS['lumeweb_cast_enqueued_styles'] = [];
        $GLOBALS['lumeweb_cast_localized_scripts'] = [];
        $GLOBALS['lumeweb_cast_hooks'] = [];

        $this->context = new FakeRequestContext();
        $this->context->screenId = 'toplevel_page_' . PublishAdminSubscriber::PAGE_SLUG;
        $this->subscriber = new PublishAdminSubscriber(
            $this->service(),
            $this->context,
        );
    }

    public function testPublishAdminSubscriberClassExists(): void
    {
        $this->assertTrue(class_exists(PublishAdminSubscriber::class));
    }

    public function testSubscribeRegistersMenuEnqueueAdminBarDomNoticeAndDismissHooksOnly(): void
    {
        $hooks = new RecordingHooks();
        $hooks->reset();

        $this->subscriber->subscribe($hooks);

        self::assertSame(
            [
                'admin_menu',
                'admin_enqueue_scripts',
                'admin_bar_menu',
                'admin_notices',
                'admin_post_' . PublishAdminSubscriber::NOTICE_DISMISS_ACTION,
            ],
            $hooks->actionNames(),
        );

        // The admin-bar node lands after core's own nodes (priority 100), so it
        // can never be clobbered by a later higher-priority core node.
        $adminBar = $hooks->actionsFor('admin_bar_menu');
        self::assertCount(1, $adminBar);
        self::assertSame(100, $adminBar[0]->priority);
        self::assertSame([$this->subscriber, 'registerAdminBar'], $adminBar[0]->callback);

        // The notice and its dismiss handler are the only DOM/admin-post hooks.
        self::assertCount(1, $hooks->actionsFor('admin_notices'));
        self::assertCount(1, $hooks->actionsFor('admin_post_' . PublishAdminSubscriber::NOTICE_DISMISS_ACTION));
    }

    public function testRegisterMenuGatesPageByCapability(): void
    {
        $this->subscriber->registerMenu();

        self::assertCount(1, $GLOBALS['lumeweb_cast_menu_pages']);
        [$pageTitle, $menuTitle, $capability, $slug, $callback] = $GLOBALS['lumeweb_cast_menu_pages'][0];
        self::assertSame('Publish', $pageTitle);
        self::assertSame('Publish', $menuTitle);
        self::assertSame('manage_options', $capability);
        self::assertSame(PublishAdminSubscriber::PAGE_SLUG, $slug);
        self::assertSame([$this->subscriber, 'render'], $callback);
    }

    public function testRegisterAssetsSkipsWithoutCapability(): void
    {
        $this->context->allowed = false;

        $this->subscriber->registerAssets();

        self::assertSame([], $GLOBALS['lumeweb_cast_enqueued_styles']);
        self::assertSame([], $GLOBALS['lumeweb_cast_enqueued_scripts']);
    }

    public function testRegisterAssetsSkipsPublishCardOffScreenButServesAdminBarShortcut(): void
    {
        $this->context->screenId = 'dashboard';

        $this->subscriber->registerAssets();

        // The card styles/orchestrator never load off the Publish page.
        self::assertSame([], $GLOBALS['lumeweb_cast_enqueued_styles']);

        // The admin-bar shortcut script DOES load on other admin screens where
        // the current ready-but-unpublished state offers an actionable control.
        $handles = array_map(
            static fn (array $script): string => (string) $script[0],
            $GLOBALS['lumeweb_cast_enqueued_scripts'],
        );
        self::assertSame(['cast-publish-adminbar'], $handles);
    }

    public function testRegisterAssetsOmitsAdminBarShortcutOnOtherAdminScreensWithoutControls(): void
    {
        // A quiet state (no eligible content) exposes no toolbar control, so no
        // admin-bar script is served off the Publish page — and no dead control
        // anchor is ever rendered by registerAdminBar either.
        $subscriber = new PublishAdminSubscriber(
            $this->serviceWithContent(false),
            $this->context,
        );
        $this->context->screenId = 'dashboard';

        $subscriber->registerAssets();

        self::assertSame([], $GLOBALS['lumeweb_cast_enqueued_styles']);
        self::assertSame([], $GLOBALS['lumeweb_cast_enqueued_scripts']);
    }

    public function testRegisterAssetsEnqueuesStylesOnPublishScreenWhenAllowed(): void
    {
        $this->subscriber->registerAssets();

        $handles = array_map(
            static fn (array $style): string => (string) $style[0],
            $GLOBALS['lumeweb_cast_enqueued_styles'],
        );
        self::assertSame(
            ['wp-base-styles', 'common', 'forms', 'dashicons', 'cast-admin'],
            $handles,
        );

        [$handle, $src] = $GLOBALS['lumeweb_cast_enqueued_styles'][4];
        self::assertSame('cast-admin', $handle);
        self::assertSame(
            'http://example.test/wp-content/plugins/cast/assets/css/cast-admin.css',
            $src,
        );
        // The dedicated sheet depends on the shared admin shell tokens.
        self::assertSame(
            ['wp-base-styles', 'common', 'forms', 'dashicons'],
            $GLOBALS['lumeweb_cast_enqueued_styles'][4][2],
        );
    }

    public function testRegisterAssetsEnqueuesOrchestratorScriptOnPublishScreen(): void
    {
        $this->subscriber->registerAssets();

        $scripts = $GLOBALS['lumeweb_cast_enqueued_scripts'];
        $handles = array_map(static fn (array $script): string => (string) $script[0], $scripts);
        // The full card orchestrator plus the small admin-bar shortcut both
        // load on the Publish page (the toolbar mirrors the page's action).
        self::assertSame(['cast-publish', 'cast-publish-adminbar'], $handles);

        $publish = $this->scriptFor('cast-publish', $scripts);
        [$handle, $src, $deps, $ver] = $publish;
        self::assertSame('cast-publish', $handle);
        self::assertSame(
            'http://example.test/wp-content/plugins/cast/assets/js/cast-publish.js',
            $src,
        );
        // Dependency-free by design — the orchestrator uses fetch() only.
        self::assertSame([], $deps);
        self::assertNotSame('', $ver);
    }

    public function testRegisterAssetsLocalizesPublishEndpointsNonceAllowlistAndPoll(): void
    {
        $this->subscriber->registerAssets();

        // The card payload is localized first; the admin-bar shortcut second.
        self::assertCount(2, $GLOBALS['lumeweb_cast_localized_scripts']);
        self::assertSame('cast-publish-adminbar', $GLOBALS['lumeweb_cast_localized_scripts'][1][0]);
        self::assertSame('castPublishAdminBar', $GLOBALS['lumeweb_cast_localized_scripts'][1][1]);

        [$handle, $objectName, $data] = $GLOBALS['lumeweb_cast_localized_scripts'][0];

        self::assertSame('cast-publish', $handle);
        self::assertSame('castPublish', $objectName);

        self::assertSame(
            [
                'status' => 'http://example.test/wp-json/cast/v1/publish/status',
                'start' => 'http://example.test/wp-json/cast/v1/publish/start',
                'now' => 'http://example.test/wp-json/cast/v1/publish/now',
                'mode' => 'http://example.test/wp-json/cast/v1/publish/mode',
                'cancel' => 'http://example.test/wp-json/cast/v1/publish/cancel',
                'artifact' => 'http://example.test/wp-json/cast/v1/publish/artifact',
                'website_create' => 'http://example.test/wp-json/cast/v1/website',
                'website_available' => 'http://example.test/wp-json/cast/v1/website/available',
                'website_link' => 'http://example.test/wp-json/cast/v1/website/link',
            ],
            $data['endpoints'],
        );

        // The guided website card routes share the publish nonce but carry
        // their own tight website_actions allowlist (a forged website action
        // can never cross into a publish/domain route or vice versa).
        self::assertSame(['website_create', 'website_available', 'website_link'], $data['website_actions']);

        // The same wp_rest REST nonce every cast/v1 publish route verifies.
        self::assertSame('NONCE_' . PublishRestRouteRegistrar::REST_NONCE_ACTION, $data['nonce']);

        self::assertSame(['start', 'now', 'mode', 'cancel', 'artifact'], $data['actions']);

        self::assertSame(
            ['intervalMs' => 5000, 'maxAttempts' => 12, 'backoffMs' => 15000],
            $data['poll'],
        );

        // The queued ETA cadence is localized from the SAME tick-delivery
        // constant the server view model derives its "Starting within N
        // seconds" line from, so a no-refresh client countdown reconciles with
        // the server copy.
        self::assertSame(TickConfig::DEFAULT_TICK_INTERVAL_SECONDS, $data['startWithinSeconds']);
    }

    /**
     * The choose-a-domain surface must be handed to the publish page when
     * the domain setup service is composed (complete portal identity): the
     * localized payload then also carries the domain REST endpoints, the
     * domain-action allowlist and the bounded verification-poll settings, so
     * the no-refresh script can drive bind/verify/delete/platform/DNS/SSL
     * against the same routes the server registers.
     */
    public function testRegisterAssetsLocalizesDomainSurfaceWhenDomainServiceAvailable(): void
    {
        $subscriber = new PublishAdminSubscriber(
            $this->service(),
            $this->context,
            $this->domainService(),
        );

        $subscriber->registerAssets();

        self::assertCount(2, $GLOBALS['lumeweb_cast_localized_scripts']);
        [, , $data] = $GLOBALS['lumeweb_cast_localized_scripts'][0];

        // Every domain route the registrar registers is localized under the
        // same castPublish.endpoints object the publish routes use.
        self::assertSame(
            $this->restUrl(DomainRestRouteRegistrar::LIST_ROUTE),
            $data['endpoints']['domain_list'],
        );
        self::assertSame(
            $this->restUrl(DomainRestRouteRegistrar::BIND_ROUTE),
            $data['endpoints']['domain_bind'],
        );
        self::assertSame(
            $this->restUrl(DomainRestRouteRegistrar::DNS_ROUTE),
            $data['endpoints']['domain_dns'],
        );
        self::assertSame(
            $this->restUrl(DomainRestRouteRegistrar::VERIFY_ROUTE),
            $data['endpoints']['domain_verify'],
        );
        self::assertSame(
            $this->restUrl(DomainRestRouteRegistrar::VALIDATE_ROUTE),
            $data['endpoints']['domain_validate'],
        );
        self::assertSame(
            $this->restUrl(DomainRestRouteRegistrar::DELETE_ROUTE),
            $data['endpoints']['domain_delete'],
        );
        self::assertSame(
            $this->restUrl(DomainRestRouteRegistrar::PLATFORM_ROUTE),
            $data['endpoints']['domain_platform'],
        );
        self::assertSame(
            $this->restUrl(DomainRestRouteRegistrar::AVAILABILITY_ROUTE),
            $data['endpoints']['domain_availability'],
        );
        self::assertSame(
            $this->restUrl(DomainRestRouteRegistrar::SSL_ROUTE),
            $data['endpoints']['domain_ssl'],
        );

        // The domain surface has its own client-side allowlist, separate from
        // the publish start/now/mode/cancel/artifact protection: a forged
        // domain action can never cross into a publish route or vice versa.
        self::assertSame(
            [
                'domain_list',
                'domain_bind',
                'domain_dns',
                'domain_verify',
                'domain_validate',
                'domain_delete',
                'domain_platform',
                'domain_availability',
                'domain_ssl',
            ],
            $data['domain_actions'],
        );

        // Verification runs on its own bounded poll budget, not the status
        // poll's budget, so a slow delegation never exhausts the live card.
        self::assertArrayHasKey('verify_poll', $data);
        self::assertArrayHasKey('intervalMs', $data['verify_poll']);
        self::assertArrayHasKey('maxAttempts', $data['verify_poll']);
    }

    /**
     * Without the domain setup service (no complete portal identity at boot)
     * the localized payload stays the publish-only surface: no domain
     * endpoints, no domain allowlist, no verification poll — a partial or
     * forged payload can never reach the domain routes.
     */
    public function testRegisterAssetsOmitsDomainSurfaceWithoutDomainService(): void
    {
        $this->subscriber->registerAssets();

        [, , $data] = $GLOBALS['lumeweb_cast_localized_scripts'][0];

        self::assertArrayNotHasKey('domain_list', $data['endpoints']);
        self::assertArrayNotHasKey('domain_bind', $data['endpoints']);
        self::assertArrayNotHasKey('domain_dns', $data['endpoints']);
        self::assertArrayNotHasKey('domain_verify', $data['endpoints']);
        self::assertArrayNotHasKey('domain_validate', $data['endpoints']);
        self::assertArrayNotHasKey('domain_delete', $data['endpoints']);
        self::assertArrayNotHasKey('domain_platform', $data['endpoints']);
        self::assertArrayNotHasKey('domain_availability', $data['endpoints']);
        self::assertArrayNotHasKey('domain_ssl', $data['endpoints']);
        self::assertArrayNotHasKey('domain_actions', $data);
        self::assertArrayNotHasKey('verify_poll', $data);
    }

    /**
     * When a complete identity + bound domain exist, render() must hand the
     * publish template a DomainDashboardView so the choose-a-domain panel
     * is actually part of the page (the existing template already renders it
     * the moment the key is provided).
     */
    public function testRenderPassesDomainDashboardViewWhenDomainDataAvailable(): void
    {
        $subscriber = new PublishAdminSubscriber(
            $this->service(),
            $this->context,
            $this->domainService(
                domains: [
                    new Domain('99', 'site.example.test', 'icann', true, 'waiting_delegation', 'gw.example.com'),
                    new Domain('7', 'name/', 'hns', false, 'active', 'gw.example.com'),
                ],
            ),
        );

        $output = $this->captureRender($subscriber);

        self::assertStringContainsString('cast-domain-panel', $output);
        self::assertStringContainsString('Choose and manage your domain', $output);
        self::assertStringContainsString('site.example.test', $output);
        self::assertStringContainsString('DNS delegation', $output);
    }

    /**
     * Without the domain setup service render() keeps the publish card
     * publish-only: no domainView key, so the template renders no domain panel
     * and nothing about domains leaks into the page (graceful null).
     */
    public function testRenderOmitsDomainPanelWithoutDomainService(): void
    {
        $output = $this->captureRender($this->subscriber);

        self::assertStringNotContainsString('cast-domain-panel', $output);
    }

    public function testAdminBarSkipsWithoutCapability(): void
    {
        $this->context->allowed = false;
        $bar = new \WP_Admin_Bar();

        $this->subscriber->registerAdminBar($bar);

        self::assertNull($bar->get_node(PublishAdminSubscriber::ADMIN_BAR_ID));
    }

    public function testAdminBarAddsLinkedStatusNodeWhenAllowed(): void
    {
        $bar = new \WP_Admin_Bar();

        $this->subscriber->registerAdminBar($bar);

        $node = $bar->get_node(PublishAdminSubscriber::ADMIN_BAR_ID);
        self::assertNotNull($node);
        $nodeData = (array) $node;
        self::assertSame(PublishAdminSubscriber::ADMIN_BAR_ID, $nodeData['id']);
        // Default status (complete env + onboarding, eligible content, no run,
        // no identity) reads as "Not published yet" through PublishAdminBarView.
        self::assertSame('Not published yet', $nodeData['title']);
        self::assertSame(
            'http://example.test/wp-admin/admin.php?page=' . PublishAdminSubscriber::PAGE_SLUG,
            $nodeData['href'],
        );
    }

    public function testAdminBarAddsActionablePublishControlInStateGatedSubmenu(): void
    {
        $bar = new \WP_Admin_Bar();

        $this->subscriber->registerAdminBar($bar);

        // The default ready-but-unpublished state offers one actionable control
        // (start) plus the stable open-page link, both under the parent node.
        $start = $bar->get_node(PublishAdminSubscriber::ADMIN_BAR_CONTROL_ID . '-start');
        self::assertNotNull($start);
        $startData = (array) $start;
        self::assertSame(PublishAdminSubscriber::ADMIN_BAR_ID, $startData['parent']);

        // The single visible/focusable "Publish to Pinner" control is the
        // data-cast-adminbar-action anchor and nothing else. The node carries
        // NO WP title/href of its own: WP renders a second default
        // role=menuitem link for any node with a title, which is exactly the
        // duplicate-label bug these assertions pin.
        self::assertFalse($startData['title'], 'the control node carries no WP title, so no duplicate default link renders');
        self::assertFalse($startData['href'], 'the control node carries no WP href, so no duplicate default link renders');
        // The one anchor carries exactly the action the toolbar script binds
        // and the fallback Publish-page href the progressive-enhancement path
        // degrades to (no script) — never a URL alone, the full anchor markup.
        $html = (string) $startData['meta']['html'];
        self::assertStringContainsString(
            'http://example.test/wp-admin/admin.php?page=' . PublishAdminSubscriber::PAGE_SLUG,
            $html,
            'the single action anchor falls back to the Publish page',
        );
        self::assertSame(1, substr_count($html, '<a '), 'exactly one action anchor in the control markup');
        self::assertSame(1, substr_count($html, 'data-cast-adminbar-action="start"'));
        self::assertSame(1, substr_count($html, 'Publish to Pinner'), 'exactly one Publish to Pinner label');
        self::assertStringNotContainsString('role="menuitem"', $html);
        // No cancel control in an idle (non-working) state.
        self::assertNull($bar->get_node(PublishAdminSubscriber::ADMIN_BAR_CONTROL_ID . '-cancel'));

        // The open-page link is always present.
        $open = $bar->get_node(PublishAdminSubscriber::ADMIN_BAR_ID . '-open');
        self::assertNotNull($open);
        self::assertSame('Open the Publish page', ((array) $open)['title']);
    }

    /**
     * Regression: the rendered #wp-admin-bar-cast-publish-control-start node
     * must contain exactly ONE "Publish to Pinner" anchor (the
     * data-cast-adminbar-action one), not the duplicate pair that a WP
     * title/href node produced alongside meta.html — one plain role=menuitem
     * link plus one data-cast-adminbar-action link. The rendered li is produced
     * by WordPress' own WP_Admin_Bar::_render_item() against the real bound
     * node tree, the same markup a browser sees inside the admin-bar submenu.
     */
    public function testAdminBarControlRendersSingleStartAnchorWithCorrectActionMarkup(): void
    {
        $bar = new \WP_Admin_Bar();

        $this->subscriber->registerAdminBar($bar);

        $html = $this->renderAdminBarNode($bar, PublishAdminSubscriber::ADMIN_BAR_CONTROL_ID . '-start');

        // Exactly one anchor and exactly one action/label — no duplicate pair.
        self::assertSame(1, substr_count($html, '<a '), 'the control li must contain a single anchor');
        self::assertSame(1, substr_count($html, 'data-cast-adminbar-action="start"'));
        self::assertSame(1, substr_count($html, 'Publish to Pinner'));

        // The surviving anchor is the JS-action one and only that one.
        self::assertStringContainsString('<a class="ab-item"', $html);
        self::assertStringContainsString('data-cast-adminbar-action="start">Publish to Pinner</a>', $html);

        // No second, plain role=menuitem link exists beside the action anchor.
        self::assertSame(0, substr_count($html, 'role="menuitem" href'), 'no plain default WP link duplicates the action');

        // The separate "Open the Publish page" item still renders as its own
        // plain WP link (it is a navigation item, never an action), and does
        // not carry the action data the toolbar script binds.
        $openHtml = $this->renderAdminBarNode($bar, PublishAdminSubscriber::ADMIN_BAR_ID . '-open');
        self::assertStringContainsString('Open the Publish page', $openHtml);
        self::assertStringNotContainsString('data-cast-adminbar-action', $openHtml);
    }

    public function testAdminBarOffersCancelControlWhileWorking(): void
    {
        // A live run maps to the working state: publish is refused but cancel
        // is offered as the toolbar control.
        $subscriber = $this->subscriberWithOngoingRun();

        $bar = new \WP_Admin_Bar();
        $subscriber->registerAdminBar($bar);

        self::assertNotNull($bar->get_node(PublishAdminSubscriber::ADMIN_BAR_CONTROL_ID . '-cancel'));
        self::assertNull($bar->get_node(PublishAdminSubscriber::ADMIN_BAR_CONTROL_ID . '-start'));
        self::assertNull($bar->get_node(PublishAdminSubscriber::ADMIN_BAR_CONTROL_ID . '-now'));
    }

    /**
     * The admin bar must never expose an actionable control when the state
     * cannot act: a quiet (no eligible content) state renders only the parent
     * node and the open-page link, never a data-cast-adminbar-action anchor.
     */
    public function testAdminBarOmitsActionableControlsWhenStateCannotAct(): void
    {
        $subscriber = new PublishAdminSubscriber($this->serviceWithContent(false), $this->context);

        $bar = new \WP_Admin_Bar();
        $subscriber->registerAdminBar($bar);

        self::assertNull($bar->get_node(PublishAdminSubscriber::ADMIN_BAR_CONTROL_ID . '-start'));
        self::assertNull($bar->get_node(PublishAdminSubscriber::ADMIN_BAR_CONTROL_ID . '-now'));
        self::assertNull($bar->get_node(PublishAdminSubscriber::ADMIN_BAR_CONTROL_ID . '-cancel'));
        self::assertNotNull($bar->get_node(PublishAdminSubscriber::ADMIN_BAR_ID . '-open'));
    }

    public function testRegisterAssetsLocalizesTightAdminBarShortcutPayload(): void
    {
        $this->subscriber->registerAssets();

        self::assertCount(2, $GLOBALS['lumeweb_cast_localized_scripts']);
        [, $objectName, $data] = $GLOBALS['lumeweb_cast_localized_scripts'][1];

        self::assertSame('castPublishAdminBar', $objectName);
        // The tight start/now/cancel allowlist — the toolbar never touches the
        // status read, the mode write or the artifact republish.
        self::assertSame(['start', 'now', 'cancel'], $data['actions']);
        self::assertSame('NONCE_' . PublishRestRouteRegistrar::REST_NONCE_ACTION, $data['nonce']);
        self::assertArrayHasKey('start', $data['endpoints']);
        self::assertArrayHasKey('now', $data['endpoints']);
        self::assertArrayHasKey('cancel', $data['endpoints']);
        self::assertArrayNotHasKey('mode', $data['endpoints']);
    }

    public function testRegisterAssetsDoesNotLocalizeAdminBarPayloadInQuietState(): void
    {
        $subscriber = new PublishAdminSubscriber($this->serviceWithContent(false), $this->context);
        $this->context->screenId = 'toplevel_page_' . PublishAdminSubscriber::PAGE_SLUG;

        $subscriber->registerAssets();

        // Only the card payload is localized; the toolar allowlist is absent.
        self::assertCount(1, $GLOBALS['lumeweb_cast_localized_scripts']);
        self::assertSame('castPublish', $GLOBALS['lumeweb_cast_localized_scripts'][0][1]);
    }

    /* ------------------------- notice visibility ------------------------- */

    public function testRenderNoticeSkipsWithoutCapability(): void
    {
        $this->context->allowed = false;

        ob_start();
        $this->subscriber->renderNotice();

        self::assertSame('', (string) ob_get_clean());
    }

    public function testRenderNoticeSkipsOnThePublishScreenItself(): void
    {
        $this->context->screenId = 'toplevel_page_' . PublishAdminSubscriber::PAGE_SLUG;

        ob_start();
        $this->subscriber->renderNotice();

        self::assertSame('', (string) ob_get_clean());
    }

    public function testRenderNoticeShowsWhenPublishIsWarranted(): void
    {
        // The notice targets every other admin screen, never the Publish page
        // itself (which already leads with the primary action).
        $this->context->screenId = 'dashboard';

        ob_start();
        $this->subscriber->renderNotice();

        $output = (string) ob_get_clean();

        self::assertStringContainsString('cast-publish-notice', $output);
        self::assertStringContainsString('Publish your changes to Pinner', $output);
        self::assertStringContainsString('Publish to Pinner', $output);
        self::assertStringContainsString(
            'http://example.test/wp-admin/admin.php?page=' . PublishAdminSubscriber::PAGE_SLUG,
            $output,
        );
        self::assertStringContainsString('name="cast_publish_dismiss"', $output);
        self::assertStringContainsString(PublishAdminSubscriber::NOTICE_DISMISS_ACTION, $output);
    }

    public function testRenderNoticeSkipsWithoutEligibleContent(): void
    {
        $subscriber = new PublishAdminSubscriber($this->serviceWithContent(false), $this->context);

        ob_start();
        $subscriber->renderNotice();

        self::assertSame('', (string) ob_get_clean());
    }

    public function testRenderNoticeSkipsWhileAutoPublishing(): void
    {
        // Auto mode owns the publish cadence; a "you should publish" prompt
        // would be misleading, so the notice must not render.
        $subscriber = new PublishAdminSubscriber($this->serviceWithMode(PublishMode::OnUpdate), $this->context);

        ob_start();
        $subscriber->renderNotice();

        self::assertSame('', (string) ob_get_clean());
    }

    public function testRenderNoticeHonorsPersistentDismissal(): void
    {
        $store = $this->dismissalStore(true);
        $subscriber = new PublishAdminSubscriber(
            $this->serviceWithOptions(noticeDismissals: $store),
            $this->context,
        );

        ob_start();
        $subscriber->renderNotice();

        self::assertSame('', (string) ob_get_clean());
    }

    public function testDismissNoticeDeniesWithoutNonce(): void
    {
        $this->context->nonceValid = false;

        $this->subscriber->dismissNotice();

        self::assertSame(1, $this->context->denyCount);
        self::assertSame([], $this->context->redirects);
    }

    public function testDismissNoticeDeniesWithoutCapability(): void
    {
        $this->context->allowed = false;

        $this->subscriber->dismissNotice();

        self::assertSame(1, $this->context->denyCount);
        self::assertSame([], $this->context->redirects);
    }

    public function testDismissNoticePersistsDismissalAndRedirectsBack(): void
    {
        $store = $this->dismissalStore(false);
        $subscriber = new PublishAdminSubscriber(
            $this->serviceWithOptions(noticeDismissals: $store),
            $this->context,
        );

        $subscriber->dismissNotice();

        self::assertTrue($store->currentUserDismissed());
        // Redirects back to the page the dismissal came from, with the admin
        // dashboard as the fallback when no referer exists.
        self::assertSame(['back:' . admin_url('index.php')], $this->context->redirects);
        self::assertSame(0, $this->context->denyCount);
    }

    /**
     * A domain setup service over a scriptable FakeDomainClient, mirroring the
     * CastPlugin composition: a complete env identity plus (optionally) a
     * registered website identity whose website id the service derives.
     *
     * @param list<Domain> $domains
     */
    private function domainService(bool $withWebsite = true, array $domains = []): DomainSetupService
    {
        $client = new FakeDomainClient();
        $client->domains = $domains;
        $identity = new InMemoryIdentityGateway();

        if ($withWebsite) {
            $identity->setCurrentIdentity(new PublishIdentity(
                websiteId: 'web-42',
                websiteName: 'My Blog',
                ipnsKeyId: 'k-ipns-7',
                ipnsKeyName: 'cast-live',
                ready: true,
            ));
        }

        return new DomainSetupService(
            env: $this->completeEnv(),
            identity: $identity,
            domains: $client,
        );
    }

    private function captureRender(PublishAdminSubscriber $subscriber): string
    {
        ob_start();
        $subscriber->render();

        return (string) ob_get_clean();
    }

    /**
     * Render a single admin-bar node through WordPress' own item renderer, so
     * the assertion reads the exact markup a browser gets inside the admin-bar
     * submenu rather than reconstructing it by hand.
     *
     * The real WP_Admin_Bar tree is normalized by _bind(), which gives every
     * node the `type` and `children` properties WP_Admin_Bar::_render_item()
     * reads and which unwraps nested items into `-default` groups. The
     * 'top-secondary' group our subscriber attaches to (core registers it on
     * real requests via wp_admin_bar_add_secondary_groups()) must exist first,
     * or _bind() orphans the whole cast subtree. Node accessors (get_node /
     * _get_node) return null once the tree is bound, so the target node is
     * pulled straight out of the bound private store.
     */
    private function renderAdminBarNode(\WP_Admin_Bar $bar, string $id): string
    {
        // Core registers this group on every request; without it _bind() skips
        // the cast subtree (parents that don't resolve are orphans).
        $bar->add_group([
            'id' => 'top-secondary',
        ]);

        $bind = new \ReflectionMethod(\WP_Admin_Bar::class, '_bind');
        $bind->setAccessible(true);
        $bind->invoke($bar);

        $nodes = new \ReflectionProperty(\WP_Admin_Bar::class, 'nodes');
        $nodes->setAccessible(true);
        $bound = $nodes->getValue($bar);

        self::assertArrayHasKey($id, $bound, "Bound admin-bar node '{$id}' not found.");
        $node = $bound[$id];

        $render = new \ReflectionMethod(\WP_Admin_Bar::class, '_render_item');
        $render->setAccessible(true);

        ob_start();
        $render->invoke($bar, $node);

        return (string) ob_get_clean();
    }

    /**
     * A subscriber over a service that already has a live (queued) run, so the
     * admin-bar/notice surfaces read the working state. The run is started
     * through the service's own startPublishNow() exactly like a toolbar
     * "publish now" click would, keeping the fixture honest about how a queued
     * run actually reaches the repository.
     */
    private function subscriberWithOngoingRun(): PublishAdminSubscriber
    {
        $service = $this->service();
        $started = $service->startPublishNow();
        self::assertTrue($started->queued);

        return new PublishAdminSubscriber($service, $this->context);
    }

    /**
     * The enqueued-script entry for the given handle.
     *
     * @param list<array{0: string, 1: string, 2: list<string>, 3: string}> $scripts
     * @return array{0: string, 1: string, 2: list<string>, 3: string}
     */
    private function scriptFor(string $handle, array $scripts): array
    {
        foreach ($scripts as $script) {
            if (($script[0] ?? null) === $handle) {
                return $script;
            }
        }

        self::fail("Expected an enqueued script handle '{$handle}'.");
    }

    /**
     * An in-memory NoticeDismissalStore for the notice visibility tests.
     */
    private function dismissalStore(bool $dismissed = false): NoticeDismissalStore
    {
        return new class ($dismissed) implements NoticeDismissalStore {
            public bool $dismissed;
            public int $clearCount = 0;

            public function __construct(bool $dismissed)
            {
                $this->dismissed = $dismissed;
            }

            public function currentUserDismissed(): bool
            {
                return $this->dismissed;
            }

            public function dismiss(): void
            {
                $this->dismissed = true;
            }

            public function clear(): void
            {
                $this->clearCount++;
                $this->dismissed = false;
            }
        };
    }

    private function restUrl(string $route): string
    {
        return 'http://example.test/wp-json/' . DomainRestRouteRegistrar::NAMESPACE . $route;
    }

    /**
     * The service and its scheduler share one mode store, mirroring the
     * production CastPlugin composition. Default status is a ready-but-untouched
     * publish surface: complete environment, terminal onboarding, eligible
     * content, Manual mode, no run and no identity.
     */
    private function service(): PublishSetupService
    {
        return $this->serviceWithOptions();
    }

    /**
     * A {@see PublishSetupService} over a scriptable content probe + mode.
     */
    private function serviceWithContent(bool $hasContent): PublishSetupService
    {
        return $this->serviceWithOptions(hasContent: $hasContent);
    }

    private function serviceWithMode(PublishMode $mode, ?NoticeDismissalStore $dismissals = null): PublishSetupService
    {
        return $this->serviceWithOptions(mode: $mode, noticeDismissals: $dismissals);
    }

    /**
     * @param bool $hasContent Whether the content probe reports eligible content.
     * @param PublishMode|null $mode The seeded publish mode (default Manual).
     * @param NoticeDismissalStore|null $noticeDismissals Optional notice persistence.
     */
    private function serviceWithOptions(
        bool $hasContent = true,
        ?PublishMode $mode = null,
        ?NoticeDismissalStore $noticeDismissals = null,
    ): PublishSetupService {
        $clock = new FixedClock(1_700_000_000);
        $repository = new InMemoryRunRepository();
        $scheduler = new InMemoryScheduler();
        $identity = new InMemoryIdentityGateway();
        $modeStore = new InMemoryPublishModeStore($mode ?? PublishMode::Manual);

        return new PublishSetupService(
            env: $this->completeEnv(),
            wizardStore: new FakeWizardStore(new Wizard(state: WizardState::Completed)),
            content: new FakePublishedContentProbe($hasContent),
            repository: $repository,
            identity: $identity,
            contentScheduler: new ContentPublishScheduler(
                clock: $clock,
                repository: $repository,
                scheduler: $scheduler,
                identity: $identity,
                modeStore: $modeStore,
                workItems: new InMemoryWorkItemRepository(),
            ),
            modeStore: $modeStore,
            clock: $clock,
            noticeDismissals: $noticeDismissals,
        );
    }

    private function completeEnv(): EnvIdentity
    {
        $reader = new class implements EnvReader {
            public function get(string $name): string|false
            {
                return match ($name) {
                    EnvIdentity::PORTAL_API_URL => 'https://account.example.test',
                    EnvIdentity::PORTAL_API_KEY => 'api-key',
                    default => false,
                };
            }
        };

        return new EnvIdentity($reader);
    }
}
