<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use ComposePress\Core\HookSubscriber;
use ComposePress\Core\Hooks;
use LumeWeb\Cast\Jobs\TickConfig;

/**
 * Admin publish surface: menu, assets and admin-bar node.
 *
 * Registers a top-level Publish admin page gated by the manage_options
 * capability, the shared admin styles that load only on that page (capability-
 * and screen-gated), and an admin-bar node pointing at the page for users who
 * may manage options. The admin-bar title comes from the tested
 * {@see PublishAdminBarView} mapping, so the indicator never re-derives a
 * policy from raw status fields. No front-end hooks are registered, so public
 * content is never touched.
 */
final class PublishAdminSubscriber implements HookSubscriber
{
    public const PAGE_SLUG = 'workspace-publish';
    public const ADMIN_BAR_ID = 'cast-publish';
    public const ADMIN_BAR_CONTROL_ID = 'cast-publish-control';
    public const NOTICE_DISMISS_ACTION = 'cast_publish_dismiss_notice';

    private const CAPABILITY = 'manage_options';
    private const MENU_TITLE = 'Publish';
    private const SCRIPT_HANDLE = 'cast-publish';
    private const ADMIN_BAR_SCRIPT_HANDLE = 'cast-publish-adminbar';
    private const ADMIN_BAR_ACTIONS = ['start', 'now', 'cancel'];
    private const POLL_INTERVAL_MS = 5000;
    // The auto-poll ENABLE flag localized to cast-publish.js: any positive
    // value arms CONTINUOUS status polling while a run is queued/active (the
    // old attempt-count cap is intentionally not enforced — the card stops at
    // a terminal run). The domain verification poll keeps its own bounded
    // budget below; only the STATUS poll is continuous.
    private const POLL_MAX_ATTEMPTS = 12;
    private const POLL_BACKOFF_MS = 15000;
    private const DOMAIN_VERIFY_POLL_INTERVAL_MS = 5000;
    private const DOMAIN_VERIFY_POLL_MAX_ATTEMPTS = 12;

    /** The current request's memoized status report, computed at most once. */
    private ?PublishStatus $currentStatus = null;

    public function __construct(
        private readonly PublishSetupService $service,
        private readonly RequestContext $context,
        private readonly ?DomainSetupService $domains = null,
        private readonly ViewRenderer $views = new ViewRenderer(),
    ) {
    }

    public function subscribe(Hooks $hooks): void
    {
        $hooks->action('admin_menu', [$this, 'registerMenu']);
        $hooks->action('admin_enqueue_scripts', [$this, 'registerAssets']);
        $hooks->action('admin_bar_menu', [$this, 'registerAdminBar'], 100);
        $hooks->action('admin_notices', [$this, 'renderNotice']);
        $hooks->action('admin_post_' . self::NOTICE_DISMISS_ACTION, [$this, 'dismissNotice']);
    }

    public function registerMenu(): void
    {
        add_menu_page(
            self::MENU_TITLE,
            self::MENU_TITLE,
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render'],
        );
    }

    /**
     * The Publish admin page — the dashboard card shell.
     *
     * Builds the explicit view data by mapping the status report through
     * {@see PublishDashboardView}, which pins every display/action decision.
     * The publish page template is part of the admin template module; until it
     * exists this renderer's only job is to serve the same deliberate mapping
     * through the shared {@see ViewRenderer} with an explicit $view.
     */
    public function render(): void
    {
        $view = PublishDashboardView::fromStatus($this->status());

        $data = [
            'menuTitle' => self::MENU_TITLE,
            'view' => $view,
        ];

        // choose-a-domain wiring: when the domain setup service is
        // composed (complete portal identity) the publish page receives its
        // DashboardView so publish.php renders the domain panel. Without the
        // service the key is simply absent — the template renders no panel and
        // nothing about domains leaks into the page (graceful null). The panel
        // is fed the onboarding terminal state so it can tell "finish
        // onboarding" apart from "publish your site first" — the two setup
        // states have different honest next steps.
        if ($this->domains !== null) {
            $data['domainView'] = $this->domains->dashboard($this->status()->onboardingComplete);
        }

        $this->views->render('publish.php', $data);
    }

    /**
     * Serve the publish surfaces, capability-gated.
     *
     * On the Publish page: the shared WP-admin shell styles plus the no-refresh
     * card orchestrator. On every admin screen (including the Publish page)
     * where the current publish state offers an actionable admin-bar control:
     * the small toolbar orchestrator with its endpoint/nonce allowlist. Nothing
     * loads for users who may not manage options, and nothing loads on the
     * front end (admin_enqueue_scripts never fires there, so the toolbar's
     * controls degrade to plain links to the Publish page).
     */
    public function registerAssets(): void
    {
        if (!$this->context->isCurrentUserAllowed(self::CAPABILITY)) {
            return;
        }

        $screenId = $this->context->currentScreenId();

        if ($screenId === 'toplevel_page_' . self::PAGE_SLUG) {
            $this->enqueueStyles();
            $this->enqueueScript();
        }

        if ($screenId !== null && $this->adminBarControls() !== []) {
            $this->enqueueAdminBarScript();
        }
    }

    /**
     * The admin-bar publish node and its controls, capability-gated.
     *
     * Only users who may manage options see the node; the title is the tested
     * {@see PublishAdminBarView} label and the node links to the Publish page
     * so the live status is one click away on both the admin and the front
     * end. Below the node, {@see PublishAdminBarView::controls()} decides which
     * state-gated actions the toolbar may offer (Publish to Pinner for an
     * actionable publish state, Cancel publish mid-run) plus an always-present
     * "Open the Publish page" link. Each actionable control renders as exactly
     * ONE anchor carrying the data-cast-adminbar-action the small admin-bar
     * script binds — the node deliberately carries no WP title/href of its
     * own, because WP would then render a second default role=menuitem link
     * next to the action anchor (a duplicate label). Without the script (e.g.
     * the front end) the same single anchor degrades to a plain link to the
     * Publish page, where the full orchestrator lives.
     */
    public function registerAdminBar(\WP_Admin_Bar $bar): void
    {
        if (!$this->context->isCurrentUserAllowed(self::CAPABILITY)) {
            return;
        }

        $view = PublishAdminBarView::fromStatus($this->status());

        $bar->add_node([
            'id' => self::ADMIN_BAR_ID,
            'title' => $view->label,
            'href' => $this->publishPageUrl(),
            'parent' => 'top-secondary',
        ]);

        // State-gated actionable controls: only when PublishAdminBarView says
        // the current status may act. A quiet/config state exposes no control,
        // so no usable toolbar action is ever displayed and none is localized.
        //
        // Each control renders from meta.html alone — NO WP title/href — so the
        // toolbar emits exactly one anchor (the data-cast-adminbar-action one
        // the admin-bar script binds). WP's default item renderer would print a
        // second, duplicate role=menuitem link carrying the same label for any
        // node with a title, hence the deliberate omission.
        foreach ($view->controls() as $control) {
            $action = (string) $control['action'];
            $label = (string) $control['label'];
            $bar->add_node([
                'id' => self::ADMIN_BAR_CONTROL_ID . '-' . $action,
                'parent' => self::ADMIN_BAR_ID,
                'meta' => [
                    'class' => 'cast-publish-adminbar-action',
                    'html' => '<a class="ab-item" href="' . esc_url($this->publishPageUrl()) . '" data-cast-adminbar-action="' . esc_attr($action) . '">' . esc_html($label) . '</a>',
                ],
            ]);
        }

        // A secondary link into the page is always useful, so the node has a
        // stable submenu on every screen.
        $bar->add_node([
            'id' => self::ADMIN_BAR_ID . '-open',
            'parent' => self::ADMIN_BAR_ID,
            'title' => 'Open the Publish page',
            'href' => $this->publishPageUrl(),
            'meta' => ['class' => 'cast-publish-adminbar-open'],
        ]);
    }

    /**
     * The dismissible "publish to Pinner" admin notice, capability/state-gated.
     *
     * Encourages publishing only when there is genuinely something to push: a
     * complete environment + onboarding + eligible content, no run in flight,
     * and manual mode (auto-publishing already owns the cadence — nagging
     * would be misleading there), while there are unpublished changes or a
     * never-published-but-eligible site. The notice never renders on the
     * Publish page itself, which already leads with the prominent primary
     * action, and it honors the user's persistent dismissal until a fresh
     * publish re-arms it.
     */
    public function renderNotice(): void
    {
        if (!$this->context->isCurrentUserAllowed(self::CAPABILITY)) {
            return;
        }

        // The Publish screen already opens with the primary action; a notice
        // there would duplicate it (and core injects admin_notices inside the
        // page header, breaking the page's own layout).
        if ($this->context->currentScreenId() === 'toplevel_page_' . self::PAGE_SLUG) {
            return;
        }

        $status = $this->status();
        if (!$this->shouldOfferNotice($status)) {
            return;
        }

        if ($this->service->isPublishNoticeDismissed()) {
            return;
        }

        $this->views->render('publish-notice.php', [
            'publishPageUrl' => $this->publishPageUrl(),
            'adminPostUrl' => admin_url('admin-post.php'),
            'dismissAction' => self::NOTICE_DISMISS_ACTION,
            'nonceField' => $this->context->nonceField(self::NOTICE_DISMISS_ACTION),
        ]);
    }

    /**
     * The admin-post dismissal for the publish-prompt notice.
     *
     * Verifies the notice's own nonce and the manage_options capability before
     * persisting the current user's dismissal, then redirects back to the page
     * the user was on. A failed check denies the request; nothing is stored.
     */
    public function dismissNotice(): void
    {
        $nonce = $this->context->requestNonce();

        if (
            !$this->context->isCurrentUserAllowed(self::CAPABILITY)
            || !$this->context->verifyNonce($nonce, self::NOTICE_DISMISS_ACTION)
        ) {
            $this->context->deny();

            return;
        }

        $this->service->dismissPublishNotice();
        $this->context->redirectBack(admin_url('index.php'));
    }

    /**
     * The current publish status, computed at most once per request.
     *
     * The page render, admin bar, notice and script gating all read the same
     * report so they can never disagree mid-request.
     */
    private function status(): PublishStatus
    {
        return $this->currentStatus ??= $this->service->status();
    }

    /**
     * The state-gated admin-bar controls for the current status.
     *
     * @return list<array{action: string, label: string}>
     */
    private function adminBarControls(): array
    {
        return PublishAdminBarView::fromStatus($this->status())->controls();
    }

    /**
     * Whether the publish-prompt notice may be shown for the current status.
     */
    private function shouldOfferNotice(PublishStatus $status): bool
    {
        if (!$status->bootstrapIdentityComplete || $status->envProblems !== []) {
            return false;
        }

        if (!$status->onboardingComplete) {
            return false;
        }

        if (!$status->hasEligibleContent) {
            return false;
        }

        // Never nag while a run is in flight or while auto-publishing owns the
        // cadence — either way the prompt would be misleading.
        if ($status->runActive || $status->autoActive) {
            return false;
        }

        // Publish is worth prompting only when there is something to push.
        return $status->dirty || $status->identity === null;
    }

    /**
     * The Publish admin page URL shared by the render, notice and admin bar.
     */
    private function publishPageUrl(): string
    {
        return admin_url('admin.php?page=' . rawurlencode(self::PAGE_SLUG));
    }

    /**
     * The shared WordPress-native admin shell styles for the Publish page.
     *
     * `wp-base-styles` ships the per-admin-scheme `--wp-admin-theme-color`
     * design tokens for classic (non-React) screens; the dedicated sheet uses
     * those tokens, so it pulls the same base handles the wizard screen does.
     */
    private function enqueueStyles(): void
    {
        wp_enqueue_style('wp-base-styles');
        wp_enqueue_style('common');
        wp_enqueue_style('forms');
        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'cast-admin',
            plugins_url('assets/css/cast-admin.css', $this->pluginFile()),
            ['wp-base-styles', 'common', 'forms', 'dashicons'],
            $this->assetVersion(dirname(__DIR__, 2) . '/assets/css/cast-admin.css'),
        );
    }

    /**
     * The no-refresh publish card orchestrator (assets/js/cast-publish.js),
     * dependency-free and served only on the Publish page for users who may
     * manage options. The localized payload hands the script exactly the
     * cast/v1 REST endpoints it may talk to, the wp_rest nonce every route
     * demands, the protected action allowlist and the continuous-poll settings
     * (interval + backoff + the auto-poll enable flag) — so a forged partial
     * payload can never reach the REST surface.
     */
    private function enqueueScript(): void
    {
        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            plugins_url('assets/js/cast-publish.js', $this->pluginFile()),
            [],
            $this->assetVersion(dirname(__DIR__, 2) . '/assets/js/cast-publish.js'),
            true,
        );

        $payload = [
            'endpoints' => $this->publishEndpoints(),
            'nonce' => $this->context->createNonce(PublishRestRouteRegistrar::REST_NONCE_ACTION),
            'actions' => [
                'start',
                'now',
                'mode',
                'cancel',
                'artifact',
            ],
            'poll' => [
                'intervalMs' => self::POLL_INTERVAL_MS,
                'maxAttempts' => self::POLL_MAX_ATTEMPTS,
                'backoffMs' => self::POLL_BACKOFF_MS,
            ],
            // The guided website card: the create/available/link routes plus a
            // tight website_actions allowlist, localized unconditionally like
            // the publish endpoints (the card only renders while awaiting a
            // website, and every call is still gated by nonce + allowlist).
            'website_actions' => [
                'website_create',
                'website_available',
                'website_link',
            ],
            // The queued ETA's tick delivery cadence, localized from the same
            // constant PublishDashboardView derives the server-rendered
            // "Starting within N seconds" line from — so a no-refresh client
            // countdown can never drift from the server copy.
            'startWithinSeconds' => TickConfig::DEFAULT_TICK_INTERVAL_SECONDS,
        ];

        // choose-a-domain surface: when the domain setup service is
        // composed (complete portal identity) the script additionally receives
        // the domain REST endpoints under the same endpoints object, its own
        // domain-action allowlist (so a forged domain action can never cross
        // into a publish route or vice versa) and the bounded verification-poll
        // budget. Without the service none of these keys exist.
        if ($this->domains !== null) {
            $payload['endpoints'] = array_merge($payload['endpoints'], $this->domainEndpoints());
            $payload['domain_actions'] = [
                'domain_list',
                'domain_bind',
                'domain_dns',
                'domain_verify',
                'domain_validate',
                'domain_delete',
                'domain_platform',
                'domain_availability',
                'domain_ssl',
            ];
            $payload['verify_poll'] = [
                'intervalMs' => self::DOMAIN_VERIFY_POLL_INTERVAL_MS,
                'maxAttempts' => self::DOMAIN_VERIFY_POLL_MAX_ATTEMPTS,
            ];
        }

        wp_localize_script(self::SCRIPT_HANDLE, 'castPublish', $payload);
    }

    /**
     * The small admin-bar shortcut orchestrator (assets/js/cast-adminbar.js),
     * dependency-free and served only on admin screens where the current
     * publish state offers an actionable toolbar control, for users who may
     * manage options. The localized payload hands it exactly the publish REST
     * endpoints it may talk to (never the status read or mode write), the
     * wp_rest nonce every route demands, a tight start/now/cancel allowlist and
     * the Publish page URL it lands on after firing — so a forged partial
     * payload can never reach a publish route from the toolbar.
     */
    private function enqueueAdminBarScript(): void
    {
        wp_enqueue_script(
            self::ADMIN_BAR_SCRIPT_HANDLE,
            plugins_url('assets/js/cast-adminbar.js', $this->pluginFile()),
            [],
            $this->assetVersion(dirname(__DIR__, 2) . '/assets/js/cast-adminbar.js'),
            true,
        );

        wp_localize_script(self::ADMIN_BAR_SCRIPT_HANDLE, 'castPublishAdminBar', [
            'nonce' => $this->context->createNonce(PublishRestRouteRegistrar::REST_NONCE_ACTION),
            'endpoints' => $this->adminBarEndpoints(),
            'actions' => self::ADMIN_BAR_ACTIONS,
            'publishPageUrl' => $this->publishPageUrl(),
        ]);
    }

    /**
     * The tight start/now/cancel subset of the publish REST endpoints the
     * admin-bar shortcut may reach. The status read, mode write and artifact
     * republish routes are deliberately NOT localized to the toolbar — a
     * forged toolbar payload can never reach them.
     *
     * @return array{start: string, now: string, cancel: string}
     */
    private function adminBarEndpoints(): array
    {
        $endpoints = $this->publishEndpoints();

        return [
            'start' => $endpoints['start'],
            'now' => $endpoints['now'],
            'cancel' => $endpoints['cancel'],
        ];
    }

    /**
     * The cast/v1 publish REST endpoints, derived from the route registrar's
     * constants so the localized client and the registered routes can never
     * drift apart.
     *
     * @return array{
     *     status: string,
     *     start: string,
     *     now: string,
     *     mode: string,
     *     cancel: string,
     *     artifact: string,
     *     website_create: string,
     *     website_available: string,
     *     website_link: string
     * }
     */
    private function publishEndpoints(): array
    {
        return [
            'status' => rest_url(PublishRestRouteRegistrar::NAMESPACE . PublishRestRouteRegistrar::STATUS_ROUTE),
            'start' => rest_url(PublishRestRouteRegistrar::NAMESPACE . PublishRestRouteRegistrar::START_ROUTE),
            'now' => rest_url(PublishRestRouteRegistrar::NAMESPACE . PublishRestRouteRegistrar::NOW_ROUTE),
            'mode' => rest_url(PublishRestRouteRegistrar::NAMESPACE . PublishRestRouteRegistrar::MODE_ROUTE),
            'cancel' => rest_url(PublishRestRouteRegistrar::NAMESPACE . PublishRestRouteRegistrar::CANCEL_ROUTE),
            'artifact' => rest_url(PublishRestRouteRegistrar::NAMESPACE . PublishRestRouteRegistrar::ARTIFACT_ROUTE),
            'website_create' => rest_url(PublishRestRouteRegistrar::NAMESPACE . PublishRestRouteRegistrar::WEBSITE_CREATE_ROUTE),
            'website_available' => rest_url(PublishRestRouteRegistrar::NAMESPACE . PublishRestRouteRegistrar::WEBSITE_AVAILABLE_ROUTE),
            'website_link' => rest_url(PublishRestRouteRegistrar::NAMESPACE . PublishRestRouteRegistrar::WEBSITE_LINK_ROUTE),
        ];
    }

    /**
     * The cast/v1 domain-setup REST endpoints, derived from the domain route
     * registrar's constants exactly like the publish endpoints so the
     * localized client and the registered routes can never drift apart.
     *
     * @return array{
     *     domain_list: string,
     *     domain_bind: string,
     *     domain_dns: string,
     *     domain_verify: string,
     *     domain_validate: string,
     *     domain_delete: string,
     *     domain_platform: string,
     *     domain_availability: string,
     *     domain_ssl: string
     * }
     */
    private function domainEndpoints(): array
    {
        return [
            'domain_list' => rest_url(DomainRestRouteRegistrar::NAMESPACE . DomainRestRouteRegistrar::LIST_ROUTE),
            'domain_bind' => rest_url(DomainRestRouteRegistrar::NAMESPACE . DomainRestRouteRegistrar::BIND_ROUTE),
            'domain_dns' => rest_url(DomainRestRouteRegistrar::NAMESPACE . DomainRestRouteRegistrar::DNS_ROUTE),
            'domain_verify' => rest_url(DomainRestRouteRegistrar::NAMESPACE . DomainRestRouteRegistrar::VERIFY_ROUTE),
            'domain_validate' => rest_url(DomainRestRouteRegistrar::NAMESPACE . DomainRestRouteRegistrar::VALIDATE_ROUTE),
            'domain_delete' => rest_url(DomainRestRouteRegistrar::NAMESPACE . DomainRestRouteRegistrar::DELETE_ROUTE),
            'domain_platform' => rest_url(DomainRestRouteRegistrar::NAMESPACE . DomainRestRouteRegistrar::PLATFORM_ROUTE),
            'domain_availability' => rest_url(DomainRestRouteRegistrar::NAMESPACE . DomainRestRouteRegistrar::AVAILABILITY_ROUTE),
            'domain_ssl' => rest_url(DomainRestRouteRegistrar::NAMESPACE . DomainRestRouteRegistrar::SSL_ROUTE),
        ];
    }

    /**
     * The plugin main file, passed to plugins_url() so core derives the real
     * `cast` plugin folder. Passing the plugin DIRECTORY instead drops the
     * folder segment from the URL and the browser requests
     * /wp-content/plugins/assets/css/... — front-page HTML, never the sheet.
     */
    private function pluginFile(): string
    {
        return dirname(__DIR__, 2) . '/cast.php';
    }

    /**
     * A real per-file cache buster derived from the asset's modification time,
     * so edits are never masked by a stale browser/admin cache.
     */
    private function assetVersion(string $file): string
    {
        $mtime = @filemtime($file);

        return (string) (is_int($mtime) ? $mtime : 0);
    }
}
