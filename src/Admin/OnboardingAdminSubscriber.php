<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use ComposePress\Core\HookSubscriber;
use ComposePress\Core\Hooks;
use LumeWeb\Cast\Onboarding\Wizard;
use LumeWeb\Cast\Onboarding\WizardService;
use LumeWeb\Cast\Onboarding\WizardState;
use LumeWeb\Cast\PageBuilder\PageBuilderCatalog;
use LumeWeb\Cast\PageBuilder\PageBuilderInstaller;
use LumeWeb\Cast\PageBuilder\PluginStateProvider;

/**
 * Admin-only "Getting Started" first-run entrypoint.
 *
 * Registers an admin menu page gated by the manage_options capability and
 * admin-post handlers (start/skip/complete/select-builder/result-recording)
 * that are all nonce + capability protected and delegated to
 * OnboardingRequestHandler. The admin_enqueue_scripts hook serves the
 * WordPress core `updates` script plus a tiny custom orchestrator only on the
 * Getting Started screen, capability-gated. No front-end hooks are registered,
 * so public content is never touched.
 *
 * All HTML is produced by explicit PHP templates under templates/admin, included
 * with an explicit $view array. Business rules (capability/state gating, action
 * names, candidate label building, install allowlist payload) stay in this
 * controller; templates only do presentation/iteration/escaping.
 */
final class OnboardingAdminSubscriber implements HookSubscriber
{
    public const PAGE_SLUG = 'workspace-getting-started';
    public const NONCE_ACTION = 'cast_onboarding';
    private const CAPABILITY = 'manage_options';
    private const MENU_TITLE = 'Getting Started';
    private const SCRIPT_HANDLE = 'cast-install';

    private const ACTION_START = 'start';
    private const ACTION_SKIP = 'skip';
    private const ACTION_COMPLETE = 'complete';
    private const ACTION_SELECT_BUILDER = 'select_builder';
    private const ACTION_RESET_BUILDER = 'reset_builder';
    private const ACTION_INSTALL_RESULT = 'install_result';
    private const ACTION_ACTIVATE_RESULT = 'activate_result';
    private const ACTION_REOPEN = 'reopen';

    public function __construct(
        private readonly OnboardingRequestHandler $handler,
        private readonly WizardService $wizard,
        private readonly RequestContext $context,
        private readonly PageBuilderCatalog $catalog,
        private readonly PageBuilderInstaller $installer,
        private readonly PluginStateProvider $plugins,
        private readonly ViewRenderer $views = new ViewRenderer(),
    ) {
    }

    public function subscribe(Hooks $hooks): void
    {
        $hooks->action('admin_menu', [$this, 'registerMenu']);
        $hooks->action('admin_notices', [$this, 'renderNotice']);
        $hooks->action('admin_enqueue_scripts', [$this, 'registerAssets']);
        $hooks->action('wp_dashboard_setup', [$this, 'setupDashboard']);
        $hooks->action('admin_post_' . $this->actionName(self::ACTION_START), [$this, 'start']);
        $hooks->action('admin_post_' . $this->actionName(self::ACTION_SKIP), [$this, 'skip']);
        $hooks->action('admin_post_' . $this->actionName(self::ACTION_COMPLETE), [$this, 'complete']);
        $hooks->action('admin_post_' . $this->actionName(self::ACTION_SELECT_BUILDER), [$this, 'selectBuilder']);
        $hooks->action('admin_post_' . $this->actionName(self::ACTION_RESET_BUILDER), [$this, 'resetBuilder']);
        $hooks->action('admin_post_' . $this->actionName(self::ACTION_INSTALL_RESULT), [$this, 'recordInstall']);
        $hooks->action('admin_post_' . $this->actionName(self::ACTION_ACTIVATE_RESULT), [$this, 'recordActivate']);
        $hooks->action('admin_post_' . $this->actionName(self::ACTION_REOPEN), [$this, 'reopen']);
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

    public function start(): void
    {
        $this->handler->start(self::NONCE_ACTION, self::CAPABILITY, self::PAGE_SLUG);
    }

    public function skip(): void
    {
        $this->handler->skip(self::NONCE_ACTION, self::CAPABILITY, self::PAGE_SLUG);
    }

    public function complete(): void
    {
        $this->handler->complete(self::NONCE_ACTION, self::CAPABILITY, self::PAGE_SLUG);
    }

    public function selectBuilder(): void
    {
        $this->handler->selectBuilder(self::NONCE_ACTION, self::CAPABILITY, self::PAGE_SLUG);
    }

    public function resetBuilder(): void
    {
        $this->handler->resetBuilder(self::NONCE_ACTION, self::CAPABILITY, self::PAGE_SLUG);
    }

    public function recordInstall(): void
    {
        $this->handler->recordInstall(self::NONCE_ACTION, self::CAPABILITY, self::PAGE_SLUG);
    }

    public function recordActivate(): void
    {
        $this->handler->recordActivate(self::NONCE_ACTION, self::CAPABILITY, self::PAGE_SLUG);
    }

    public function reopen(): void
    {
        $this->handler->reopen(self::NONCE_ACTION, self::CAPABILITY, self::PAGE_SLUG);
    }

    /**
     * The Getting Started admin page — the guided wizard shell.
     *
     * Builds the explicit view data by mapping the persisted aggregate through
     * WizardView (one screen identifier + presentation booleans), plus the
     * protected action names, nonce, choice cards, scoped installer rows and
     * result message. Every decision lives here; the templates only iterate and
     * escape. Rendering never mutates state.
     */
    public function render(): void
    {
        $wizard = $this->wizard->current();
        $view = WizardView::fromWizard($wizard, $this->catalog, $this->plugins);

        $this->views->render('page.php', [
            'menuTitle' => self::MENU_TITLE,
            'screen' => $view->screen,
            'progressSteps' => $this->progressSteps($view->progressStep),
            'adminPostUrl' => admin_url('admin-post.php'),
            'nonceField' => $this->context->nonceField(self::NONCE_ACTION),
            'startAction' => $this->actionName(self::ACTION_START),
            'skipAction' => $this->actionName(self::ACTION_SKIP),
            'completeAction' => $this->actionName(self::ACTION_COMPLETE),
            'selectBuilderAction' => $this->actionName(self::ACTION_SELECT_BUILDER),
            'resetBuilderAction' => $this->actionName(self::ACTION_RESET_BUILDER),
            'reopenAction' => $this->actionName(self::ACTION_REOPEN),
            'canStart' => $view->canStart,
            'gettingStartedUrl' => admin_url('admin.php?page=' . rawurlencode(self::PAGE_SLUG)),
            'choices' => $view->screen === WizardView::SCREEN_CHOOSE
                ? WizardView::choiceRows($this->catalog, WizardService::NATIVE_BUILDER)
                : [],
            'selectedBuilderLabel' => $view->selectedBuilderLabel,
            'selectedBuilderSlug' => $view->selectedBuilderSlug,
            'readyToComplete' => $view->readyToComplete,
            'canSkip' => $view->canSkip,
            'canReopen' => $view->canReopen,
            'canFinish' => $view->canFinish,
            'resultMessage' => WizardView::resultMessage($view->resultCode),
            'installRows' => $this->wizardInstallRows($wizard),
            'editorUrl' => admin_url('post-new.php'),
            'dashboardUrl' => admin_url('index.php'),
        ]);
    }

    /**
     * Serve the modern WordPress 7.1 admin styling plus the tiny Cast installer
     * orchestrator, capability-gated.
     *
     * The shared modern admin styles load on the dashboard (for the full-width
     * onboarding panel that replaces the default welcome panel) and on the
     * workspace wizard screen. Styles come from shipped, non-React core handles:
     * `wp-base-styles` (the per-admin-scheme `--wp-admin-theme-color` design
     * tokens) plus common/forms/dashicons, then the dedicated wizard layout
     * sheet. The `updates` script and the no-refresh installer orchestrator are
     * served only on the wizard screen, where the install allowlist payload is
     * localized (scoped to the selected builder) along with the result-recording
     * endpoint, nonce and protected action names. The custom script only ever
     * acts on slugs present in that payload.
     */
    public function registerAssets(): void
    {
        if (!$this->context->isCurrentUserAllowed(self::CAPABILITY)) {
            return;
        }

        $screenId = $this->context->currentScreenId();
        $wizardScreen = 'toplevel_page_' . self::PAGE_SLUG;

        if ($screenId !== 'dashboard' && $screenId !== $wizardScreen) {
            return;
        }

        $this->enqueueStyles();

        if ($screenId !== $wizardScreen) {
            return;
        }

        wp_enqueue_script('updates');

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            plugins_url('assets/js/cast-install.js', $this->pluginFile()),
            ['updates'],
            $this->assetVersion(dirname(__DIR__, 2) . '/assets/js/cast-install.js'),
            true,
        );

        wp_localize_script(self::SCRIPT_HANDLE, 'castInstall', [
            'rows' => $this->wizardInstallRows($this->wizard->current()),
            'adminPostUrl' => admin_url('admin-post.php'),
            'nonce' => $this->context->createNonce(self::NONCE_ACTION),
            'actions' => [
                'installResult' => $this->actionName(self::ACTION_INSTALL_RESULT),
                'activateResult' => $this->actionName(self::ACTION_ACTIVATE_RESULT),
            ],
        ]);
    }

    /**
     * The WordPress-native admin shell styles for the modern PHP-rendered UI.
     *
     * `wp-base-styles` ships the per-admin-scheme `--wp-admin-theme-color` and
     * `--wp-admin-border-width-focus` tokens available on classic (non-React)
     * screens; the `--wpds-*` design tokens require React package styles that
     * do not load here, so the sheet uses the wp-base-styles tokens with
     * fallbacks to normal WP colors.
     */
    private function enqueueStyles(): void
    {
        // The single render-blocking cast-admin.css <link> delivers the full
        // dedicated layout on both surfaces. Because it is a render-blocking
        // stylesheet in the head, the browser cannot paint the page before it
        // is applied — there is no first-paint flash to patch, so no separate
        // inline "critical" block or wp_add_inline_style machinery is needed.
        // The sheet's selectors carry the specificity to win the cascade (see
        // `.wrap.cast-wizard-wrap` in assets/css/cast-admin.css).
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
     * Dashboard decluttering + welcome-panel replacement, admin-only.
     *
     * Runs on the `wp_dashboard_setup` hook (fired by core before the welcome
     * panel and widgets render on wp-admin/index.php). For users who may manage
     * options it removes the noisy core widgets while preserving Site Health,
     * PHP/browser/security nags and update surfaces, and swaps the default
     * welcome panel for the shared full-width workspace onboarding panel while
     * onboarding is active. Users who cannot manage options are left untouched,
     * so they never receive an empty welcome panel.
     */
    public function setupDashboard(): void
    {
        if (!$this->context->isCurrentUserAllowed(self::CAPABILITY)) {
            return;
        }

        $this->declutterDashboard();
        $this->configureWelcomePanel();
    }

    /**
     * Remove the noisy dashboard widgets. Site Health, PHP/browser/security
     * nags and update surfaces are deliberately preserved.
     */
    private function declutterDashboard(): void
    {
        remove_meta_box('dashboard_primary', 'dashboard', 'side');
        remove_meta_box('dashboard_right_now', 'dashboard', 'normal');
        remove_meta_box('dashboard_activity', 'dashboard', 'normal');
        remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
    }

    /**
     * Replace the default welcome panel with the shared workspace welcome
     * panel while onboarding is active, never leaving an empty panel behind.
     *
     * The default core panel is always removed so a finished user sees no
     * stale "Welcome to WordPress" prompt; the replacement is registered only
     * for the active onboarding states (not started / building) so a finished
     * user gets no empty panel either.
     */
    private function configureWelcomePanel(): void
    {
        remove_action('welcome_panel', 'wp_welcome_panel');

        $state = $this->wizard->current()->state;

        if ($state === WizardState::NotStarted || $state === WizardState::Building) {
            add_action('welcome_panel', [$this, 'renderDashboardWelcome']);
        }
    }

    /**
     * The full-width workspace onboarding panel rendered inside the dashboard
     * welcome-panel region. Defensively re-checks capability and active state
     * so an empty panel can never be served, then renders the same welcome
     * partial the wizard uses.
     */
    public function renderDashboardWelcome(): void
    {
        if (!$this->context->isCurrentUserAllowed(self::CAPABILITY)) {
            return;
        }

        $state = $this->wizard->current()->state;

        if ($state !== WizardState::NotStarted && $state !== WizardState::Building) {
            return;
        }

        $this->views->render('welcome.php', $this->welcomeView());
    }

    /**
     * The view data shared by the wizard welcome screen and the dashboard
     * welcome panel, so both surfaces render the exact same welcome partial.
     *
     * Supplies the protected start/skip actions, the admin-post endpoint, the
     * nonce field and whether the secondary Skip action may be offered. Rendering
     * never mutates state. `canStart` is false once the wizard is already
     * building, so the card offers a Continue link instead of a fresh start form.
     *
     * @return array{
     *     adminPostUrl: string,
     *     nonceField: string,
     *     startAction: string,
     *     skipAction: string,
     *     canSkip: bool,
     *     canStart: bool,
     *     gettingStartedUrl: string
     * }
     */
    private function welcomeView(): array
    {
        $wizard = $this->wizard->current();
        $view = WizardView::fromWizard($wizard, $this->catalog, $this->plugins);

        return [
            'adminPostUrl' => admin_url('admin-post.php'),
            'nonceField' => $this->context->nonceField(self::NONCE_ACTION),
            'startAction' => $this->actionName(self::ACTION_START),
            'skipAction' => $this->actionName(self::ACTION_SKIP),
            'canSkip' => $view->canSkip,
            'canStart' => $view->canStart,
            'gettingStartedUrl' => admin_url('admin.php?page=' . rawurlencode(self::PAGE_SLUG)),
        ];
    }

    /**
     * Dashboard notice/entrypoint shown to admins who have not finished
     * onboarding.
     *
     * Rendered only on admin pages for current users who hold the same
     * capability that gates the Getting Started page, and only while the
     * wizard state is not_started or building. Skipped and completed states,
     * and users without the capability, see nothing.
     */
    public function renderNotice(): void
    {
        if (!$this->context->isCurrentUserAllowed(self::CAPABILITY)) {
            return;
        }

        // The notice is a dashboard entrypoint INTO the wizard. On the wizard's
        // own page it would be redundant (the page already offers Get Started /
        // Skip), and core injects admin_notices output inside the page's header
        // markup, breaking the wizard header layout. Suppress it there.
        if ($this->context->currentScreenId() === 'toplevel_page_' . self::PAGE_SLUG) {
            return;
        }

        $state = $this->wizard->current()->state;

        if ($state === WizardState::Skipped || $state === WizardState::Completed) {
            return;
        }

        $this->views->render('notice.php', [
            'gettingStartedUrl' => admin_url('admin.php?page=' . rawurlencode(self::PAGE_SLUG)),
            'adminPostUrl' => admin_url('admin-post.php'),
            'skipAction' => $this->actionName(self::ACTION_SKIP),
            'nonceField' => $this->context->nonceField(self::NONCE_ACTION),
        ]);
    }

    /**
     * The three ordered wizard progress steps with the active one marked.
     *
     * $currentStep is the 1-based position reported by WizardView (1 Choose,
     * 2 Install, 3 Complete); 0 (the welcome screen) and any out-of-range value
     * mark no step current so the tracker only lights steps that exist.
     *
     * @return list<array{label: string, current: bool}>
     */
    private function progressSteps(int $currentStep): array
    {
        return array_map(
            static fn (array $step, int $index): array => [
                'label' => (string) $step['label'],
                'current' => $index + 1 === $currentStep,
            ],
            WizardView::PROGRESS_STEPS,
            array_keys(WizardView::PROGRESS_STEPS),
        );
    }

    /**
     * The installer allowlist payload scoped to the wizard's current selection.
     *
     * Only the selected builder's install row is ever rendered or localized, so
     * the page shows one decisive row and the `castInstall.rows` allowlist a
     * forged script could act on contains exactly the same single slug. The
     * native selection (no plugin slug) and unknown slugs yield no rows.
     *
     * @return list<array{
     *     slug: string,
     *     label: string,
     *     name: string,
     *     basename: ?string,
     *     alreadyInstalled: bool,
     *     alreadyActive: bool,
     *     buttonLabel: string,
     *     buttonDisabled: bool
     * }>
     */
    private function wizardInstallRows(Wizard $wizard): array
    {
        if ($wizard->selectedBuilder === null) {
            return [];
        }

        $row = $this->installer->payloadForSlug($wizard->selectedBuilder);

        return $row === null ? [] : [$row];
    }

    private function actionName(string $action): string
    {
        return 'cast_onboarding_' . $action;
    }

    /**
     * The plugin main file, passed to plugins_url() so core derives the real
     * `cast` plugin folder. Passing the plugin DIRECTORY instead (as this served
     * before) makes plugin_basename() return `cast` with no folder segment, so
     * core drops the folder from the URL and the browser requests
     * /wp-content/plugins/assets/css/... — front-page HTML, never the
     * stylesheet.
     */
    private function pluginFile(): string
    {
        return dirname(__DIR__, 2) . '/cast.php';
    }

    /**
     * A real per-file cache buster derived from the asset's modification time,
     * so edits are never masked by a stale browser/admin cache — and never the
     * core WordPress version fallback (7.1) that a no-version enqueue silently
     * produced before.
     */
    private function assetVersion(string $file): string
    {
        $mtime = @filemtime($file);

        return (string) (is_int($mtime) ? $mtime : 0);
    }
}
