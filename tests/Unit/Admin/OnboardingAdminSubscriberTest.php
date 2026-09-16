<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use ComposePress\Core\Testing\RecordingHooks;
use LumeWeb\Cast\Admin\OnboardingAdminSubscriber;
use LumeWeb\Cast\Admin\OnboardingRequestHandler;
use LumeWeb\Cast\Onboarding\InstallStatus;
use LumeWeb\Cast\Onboarding\ResultCode;
use LumeWeb\Cast\Onboarding\Wizard;
use LumeWeb\Cast\Onboarding\WizardService;
use LumeWeb\Cast\Onboarding\WizardState;
use LumeWeb\Cast\PageBuilder\PageBuilderCatalog;
use LumeWeb\Cast\PageBuilder\PageBuilderInstaller;
use LumeWeb\Cast\Tests\Unit\Onboarding\FakeWizardStore;
use LumeWeb\Cast\Tests\Unit\PageBuilder\FakePluginStateProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OnboardingAdminSubscriberTest extends TestCase
{
    private FakeWizardStore $store;
    private FakeRequestContext $context;
    private FakePluginStateProvider $plugins;
    private OnboardingAdminSubscriber $subscriber;

    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_menu_pages'] = [];
        $GLOBALS['lumeweb_cast_enqueued_scripts'] = [];
        $GLOBALS['lumeweb_cast_localized_scripts'] = [];
        $GLOBALS['lumeweb_cast_enqueued_styles'] = [];
        $GLOBALS['lumeweb_cast_removed_meta_boxes'] = [];
        // The add_action/remove_action shim writes here; reset so dashboard
        // setup tests do not leak hook registrations into one another.
        $GLOBALS['lumeweb_cast_hooks'] = [];
        $this->store = new FakeWizardStore();
        $this->context = new FakeRequestContext();
        $this->plugins = new FakePluginStateProvider();
        $wizard = new WizardService($this->store);
        $handler = new OnboardingRequestHandler($wizard, $this->context);
        $this->subscriber = new OnboardingAdminSubscriber(
            $handler,
            $wizard,
            $this->context,
            new PageBuilderCatalog(),
            new PageBuilderInstaller(new PageBuilderCatalog(), $this->plugins),
            $this->plugins,
        );
    }

    public function testSubscribeRegistersAdminMenuNoticesEnqueueDashboardAndPostActionsOnly(): void
    {
        $hooks = new RecordingHooks();
        $hooks->reset();

        $this->subscriber->subscribe($hooks);

        self::assertSame(
            [
                'admin_menu',
                'admin_notices',
                'admin_enqueue_scripts',
                'wp_dashboard_setup',
                'admin_post_cast_onboarding_start',
                'admin_post_cast_onboarding_skip',
                'admin_post_cast_onboarding_complete',
                'admin_post_cast_onboarding_select_builder',
                'admin_post_cast_onboarding_reset_builder',
                'admin_post_cast_onboarding_install_result',
                'admin_post_cast_onboarding_activate_result',
                'admin_post_cast_onboarding_reopen',
            ],
            $hooks->actionNames(),
        );
    }

    public function testRegisterMenuGatesPageByCapability(): void
    {
        $this->subscriber->registerMenu();

        self::assertCount(1, $GLOBALS['lumeweb_cast_menu_pages']);
        [$pageTitle, $menuTitle, $capability, $slug] = $GLOBALS['lumeweb_cast_menu_pages'][0];
        self::assertSame('Getting Started', $pageTitle);
        self::assertSame('Getting Started', $menuTitle);
        self::assertSame('manage_options', $capability);
        self::assertSame(OnboardingAdminSubscriber::PAGE_SLUG, $slug);
    }

    public function testWelcomeScreenRendersWPAdminShellAndStartSkipControls(): void
    {
        $output = $this->renderPage();

        self::assertStringContainsString('class="wrap', $output);
        self::assertStringContainsString('Getting Started', $output);
        // The raw aggregate state must never be dumped as the primary UI.
        self::assertStringNotContainsString('First-run onboarding status', $output);
        self::assertStringNotContainsString('not_started', $output);

        // Start and Skip both post to admin-post through protected actions.
        self::assertStringContainsString('value="cast_onboarding_start"', $output);
        self::assertStringContainsString('value="cast_onboarding_skip"', $output);
        self::assertStringContainsString('NONCE_FIELD_', $output);
        // WordPress admin button conventions, no inline styles.
        self::assertStringContainsString('class="button button-primary', $output);
    }

    public function testWelcomeScreenUsesAccessibleSemanticHeadings(): void
    {
        $output = $this->renderPage();

        self::assertMatchesRegularExpression('/<h1[^>]*>Getting Started<\/h1>/', $output);
        self::assertMatchesRegularExpression('/<h2[^>]*id="cast-welcome-heading"[^>]*>/', $output);
        // The welcome card is a `.card` in the WP admin visual language.
        self::assertStringContainsString('class="card cast-welcome-card"', $output);
    }

    public function testWelcomeScreenHeaderSubtitleSpeaksToTheEndUser(): void
    {
        $output = $this->renderPage();

        // The wizard header promises the end user what the setup journey does,
        // in plain user-facing terms — not internal product language. The
        // assertion matches the live escaped HTML the template actually serves
        // (esc_html() encodes the apostrophe).
        self::assertStringContainsString('Choose how you want to build, then we&#039;ll help you get started.', $output);
    }

    public function testWelcomeScreenUsesWorkspaceSetupCopy(): void
    {
        $output = $this->renderPage();

        // The welcome experience speaks to the user's own workspace setup, not
        // internal product branding: a title heading plus its own subtitle line.
        self::assertStringContainsString('Set up your workspace', $output);
        self::assertStringContainsString('Finish setting up your site in a couple of minutes.', $output);
        // The old merged single-line subtitle never regresses back into the page.
        self::assertStringNotContainsString('Let&#039;s get your site ready', $output);
    }

    public function testWelcomeScreenGetStartedIsPrimaryWordPressButton(): void
    {
        $output = $this->renderPage();

        // Get Started is the decisive primary action in WP admin button terms.
        self::assertMatchesRegularExpression(
            '/<button[^>]*class="button button-primary cast-action-full"[^>]*>Get Started<\/button>/',
            $output,
        );
        self::assertStringContainsString('value="cast_onboarding_start"', $output);
    }

    public function testWelcomeScreenSkipIsSecondaryWordPressButton(): void
    {
        $output = $this->renderPage();

        // Skip for now is a deliberate secondary WordPress action: it uses the
        // plain `.button` class (it never becomes a `.button-primary`), possibly
        // with the full-width layout class shared with the dashboard panel.
        self::assertMatchesRegularExpression(
            '/<button[^>]*class="button[^"]*"[^>]*>Skip for now<\/button>/',
            $output,
        );
        self::assertStringNotContainsString(
            '<button type="submit" class="button button-primary cast-action-full">Skip for now</button>',
            $output,
        );
    }

    public function testChooseScreenRendersAlignedSelectableCardsFromCatalog(): void
    {
        $this->store->stored = new Wizard(state: WizardState::Building);

        $output = $this->renderPage();

        self::assertMatchesRegularExpression('/<h2[^>]*id="cast-choose-heading"[^>]*>/', $output);
        // Radio-card semantics inside a fieldset/legend.
        self::assertMatchesRegularExpression('/<fieldset[^>]*class="cast-choice-fieldset"/', $output);
        self::assertMatchesRegularExpression('/<legend[^>]*>/', $output);

        // The aligned cards are catalog-driven: native first, then every
        // selectable free-version choice — with radio values the backend accepts.
        self::assertMatchesRegularExpression('/value="native"[^>]*>/', $output);
        self::assertMatchesRegularExpression('/value="brizy"[^>]*>/', $output);
        self::assertMatchesRegularExpression('/value="generateblocks"[^>]*>/', $output);
        self::assertMatchesRegularExpression('/value="elementor"[^>]*>/', $output);
        self::assertMatchesRegularExpression('/value="beaver-builder-lite-version"[^>]*>/', $output);
        self::assertStringContainsString('Native Gutenberg / Site Editor', $output);
        self::assertStringContainsString('Brizy (Free)', $output);
        self::assertStringContainsString('GenerateBlocks (Free)', $output);
        self::assertStringContainsString('Elementor (Free)', $output);
        self::assertStringContainsString('Beaver Builder Lite (Free)', $output);

        // Honest free/cost, lock-in and performance notes ride on every card.
        self::assertStringContainsString('Part of WordPress core', $output);
        self::assertStringContainsString('No extra plugin needed', $output);
        self::assertStringContainsString('Build pages with blocks and the Site Editor', $output);
        self::assertStringContainsString('Pages made with Brizy need Brizy available when editing them', $output);
        self::assertStringContainsString('Visual drag-and-drop page design', $output);

        // Excluded tooling never leaks into the page.
        self::assertStringNotContainsString('spectra', $output);
        self::assertStringNotContainsString('breakdance', $output);
        self::assertStringNotContainsString('data-cast-slug="elementor"', $output);

        // Continue posts the protected select_builder action.
        self::assertStringContainsString('value="cast_onboarding_select_builder"', $output);
        self::assertStringContainsString('>Continue</button>', $output);
    }

    public function testChooseScreenShowsProgressIndicator(): void
    {
        $this->store->stored = new Wizard(state: WizardState::Building);

        $output = $this->renderPage();

        self::assertStringContainsString('class="cast-progress cast-progress-horizontal"', $output);
        self::assertStringContainsString('aria-current="step"', $output);
        self::assertStringContainsString('aria-label="Onboarding progress"', $output);
    }

    public function testInstallScreenRendersOnlySelectedBuilderRow(): void
    {
        $this->store->stored = new Wizard(
            state: WizardState::Building,
            step: 'builder_selected',
            selectedBuilder: 'brizy',
            installStatus: InstallStatus::Idle,
        );

        $output = $this->renderPage();

        self::assertStringContainsString('data-cast-slug="brizy"', $output);
        self::assertStringContainsString('cast-install-button', $output);
        // Only the selected builder is actionable; the other candidate is not.
        self::assertStringNotContainsString('data-cast-slug="generateblocks"', $output);

        // The no-refresh orchestrator announces progress through a live region.
        self::assertStringContainsString('id="cast-install-live"', $output);
        self::assertStringContainsString('aria-live="polite"', $output);

        // A plugin that is not yet active cannot be completed.
        self::assertStringNotContainsString('value="cast_onboarding_complete"', $output);

        // The user may change their mind or skip.
        self::assertStringContainsString('value="cast_onboarding_reset_builder"', $output);
    }

    public function testActiveBuilderAdvancesToCompleteScreenWithFinishAction(): void
    {
        // Persisted aggregate and runtime provider agree the plugin is active.
        $this->plugins->installed['brizy'] = 'brizy/brizy.php';
        $this->plugins->active = ['brizy/brizy.php'];

        $this->store->stored = new Wizard(
            state: WizardState::Building,
            step: 'active',
            selectedBuilder: 'brizy',
            installStatus: InstallStatus::Active,
            resultCode: ResultCode::ActivateSucceeded,
        );

        $output = $this->renderPage();

        // A confirmed-active builder renders the Complete screen (Step 3), not
        // the Install screen with a stale "Complete setup" button. The Finish
        // action still posts the protected complete action so the aggregate can
        // move from Building to terminal Completed only when the user accepts.
        self::assertStringContainsString('Setup complete', $output);
        self::assertStringContainsString('value="cast_onboarding_complete"', $output);
        self::assertStringContainsString('Finish setup', $output);
        self::assertStringNotContainsString('Complete setup', $output);
        // No installer controls on the complete screen.
        self::assertStringNotContainsString('cast-install-button', $output);
        self::assertStringNotContainsString('data-cast-slug="brizy"', $output);
        // A different builder is still a valid way to change the selection.
        self::assertStringContainsString('value="cast_onboarding_reset_builder"', $output);
    }

    public function testNativeSelectionShowsReadyStateWithNoInstallerControls(): void
    {
        $this->store->stored = new Wizard(
            state: WizardState::Building,
            step: 'ready',
        );

        $output = $this->renderPage();

        // Nothing to install: no install button, no live region button.
        self::assertStringNotContainsString('cast-install-button', $output);
        self::assertStringContainsString('Native Gutenberg / Site Editor', $output);
        // Native is immediately ready to complete via the real backend action.
        self::assertStringContainsString('value="cast_onboarding_complete"', $output);
    }

    public function testInstallScreenSurfacesFailureResultCodeWithRetryPath(): void
    {
        $this->store->stored = new Wizard(
            state: WizardState::Building,
            step: 'install_failed',
            selectedBuilder: 'generateblocks',
            installStatus: InstallStatus::Failed,
            resultCode: ResultCode::InstallFailed,
        );

        $output = $this->renderPage();

        // An inline error panel surfaces the structured result code and retry.
        self::assertMatchesRegularExpression('/class="notice notice-error cast-error-panel"/', $output);
        self::assertStringContainsString('could not be installed', $output);
        // The install button is back in a retryable (non-disabled) state.
        self::assertStringContainsString('data-cast-slug="generateblocks"', $output);
        self::assertStringNotContainsString('value="cast_onboarding_complete"', $output);
    }

    public function testCompleteScreenShowsStatusAndReopen(): void
    {
        $this->store->stored = new Wizard(
            state: WizardState::Completed,
            step: 'active',
            selectedBuilder: 'brizy',
            installStatus: InstallStatus::Active,
            resultCode: ResultCode::ActivateSucceeded,
        );

        $output = $this->renderPage();

        self::assertStringContainsString('Brizy (Free)', $output);
        // Open the editor and return to the dashboard.
        self::assertStringContainsString('post-new.php', $output);
        self::assertStringContainsString('Return to dashboard', $output);
        // Reopen setup posts the protected reopen action.
        self::assertStringContainsString('value="cast_onboarding_reopen"', $output);
    }

    /* --------------------- completion action UI --------------------- */

    /**
     * Shared fixture: an activated builder on the complete Step-3 screen where
     * the user still has to accept the Finish action (aggregate stays Building
     * until the protected complete transition runs). This is exactly the state
     * the reported bad-spacing/unstyled-action complaint was filed against.
     */
    private function storedCompleteActiveWizard(): void
    {
        // Keep the persisted aggregate AND the runtime provider in agreement:
        // the plugin is actually active, so the view model must show Step 3.
        $this->plugins->installed['brizy'] = 'brizy/brizy.php';
        $this->plugins->active = ['brizy/brizy.php'];

        $this->store->stored = new Wizard(
            state: WizardState::Building,
            step: 'complete',
            selectedBuilder: 'brizy',
            installStatus: InstallStatus::Active,
            resultCode: ResultCode::ActivateSucceeded,
        );
    }

    public function testCompleteScreenCreateFirstPageIsPrimaryWordPressButtonWithEditorTarget(): void
    {
        $this->storedCompleteActiveWizard();

        $output = $this->renderPage();

        // The decisive completion action is a WordPress PRIMARY button link that
        // opens the editor to create a first page (an intentional target URL) —
        // never a bare, unstyled anchor.
        self::assertMatchesRegularExpression(
            '/<a [^>]*class="button button-primary cast-action-full"[^>]*href="[^"]*post-new\.php[^"]*"[^>]*>Create your first page<\/a>/',
            $output,
        );
    }

    public function testCompleteScreenFinishSetupIsSecondarySeparatedFormControl(): void
    {
        $this->storedCompleteActiveWizard();

        $output = $this->renderPage();

        // Finish setup confirms the flow as a plain SECONDARY WordPress button
        // (never a second full-width primary CTA), kept in its own protected
        // form with a nonce and the backend complete action.
        self::assertMatchesRegularExpression(
            '/<button type="submit" class="button">Finish setup<\/button>/',
            $output,
        );
        self::assertStringContainsString('value="cast_onboarding_complete"', $output);
        self::assertStringContainsString('NONCE_FIELD_cast_onboarding', $output);
    }

    public function testCompleteScreenPrimaryVisualAppearsExactlyOnce(): void
    {
        $this->storedCompleteActiveWizard();

        $output = $this->renderPage();

        // The full-width primary visual lives on the one decisive action only;
        // the Finish confirmation must never compete for the primary treatment
        // (this is the reported "two primary CTAs back-to-back" regression).
        self::assertSame(
            1,
            preg_match_all('/class="button button-primary cast-action-full"/', $output),
            'The full-width primary action class must appear exactly once on the completion screen.',
        );
    }

    public function testCompleteScreenReturnToDashboardIsStyledLinkAction(): void
    {
        $this->storedCompleteActiveWizard();

        $output = $this->renderPage();

        // Return to dashboard is a proper WordPress link-button (button-link) —
        // a styled, accessible link action with an intentional dashboard target,
        // never the reported plain unstyled anchor.
        self::assertMatchesRegularExpression(
            '/<a [^>]*class="button-link"[^>]*href="[^"]*index\.php[^"]*"[^>]*>Return to dashboard<\/a>/',
            $output,
        );
    }

    /* --------------------- runtime plugin-state reconciliation --------------------- */

    public function testActivePluginWithStalePersistedInstallStateRendersCompleteScreen(): void
    {
        // The persisted aggregate is stale (builder selected / idle) but the
        // actual plugin is active at runtime: the rendered wizard must expose
        // Step 3 with usable Create your first page + Finish setup actions —
        // never the dead-end Install screen with no continuation.
        $this->store->stored = new Wizard(
            state: WizardState::Building,
            step: 'builder_selected',
            selectedBuilder: 'brizy',
            installStatus: InstallStatus::Idle,
        );
        $this->plugins->installed['brizy'] = 'brizy/brizy.php';
        $this->plugins->active = ['brizy/brizy.php'];

        $output = $this->renderPage();

        self::assertStringContainsString('Setup complete', $output);
        self::assertStringContainsString('Create your first page', $output);
        self::assertStringContainsString('value="cast_onboarding_complete"', $output);
        self::assertStringContainsString('Finish setup', $output);
        self::assertStringContainsString('value="cast_onboarding_reset_builder"', $output);
        // The dead-end Install screen and its controls are gone.
        self::assertStringNotContainsString('Install your page builder', $output);
        self::assertStringNotContainsString('data-cast-slug="brizy"', $output);
        self::assertStringNotContainsString('cast-install-button', $output);
        self::assertStringNotContainsString('Skip for now', $output);
    }

    public function testActivePluginWithStaleFailureResultStillAdvancesToCompleteScreen(): void
    {
        // A prior redirect/error left an install_failed result behind even
        // though the plugin is actually active: runtime state is authoritative.
        $this->store->stored = new Wizard(
            state: WizardState::Building,
            step: 'install_failed',
            selectedBuilder: 'brizy',
            installStatus: InstallStatus::Failed,
            resultCode: ResultCode::InstallFailed,
        );
        $this->plugins->installed['brizy'] = 'brizy/brizy.php';
        $this->plugins->active = ['brizy/brizy.php'];

        $output = $this->renderPage();

        self::assertStringContainsString('Setup complete', $output);
        self::assertStringContainsString('Finish setup', $output);
        // The stale failure panel must not surface on the reconciled screen.
        self::assertStringNotContainsString('could not be installed', $output);
        self::assertStringNotContainsString('Install your page builder', $output);
    }

    public function testInactivePluginStaysOnInstallWithRetryDespiteStalePersistedActive(): void
    {
        // The plugin is installed but NOT active at runtime. A stale persisted
        // "active/complete" must never be trusted: the wizard stays on Install
        // with the Activate retry, never the Complete screen.
        $this->store->stored = new Wizard(
            state: WizardState::Building,
            step: 'complete',
            selectedBuilder: 'brizy',
            installStatus: InstallStatus::Active,
            resultCode: ResultCode::ActivateSucceeded,
        );
        $this->plugins->installed['brizy'] = 'brizy/brizy.php';
        $this->plugins->active = [];

        $output = $this->renderPage();

        self::assertStringContainsString('Install your page builder', $output);
        self::assertStringContainsString('data-cast-slug="brizy"', $output);
        self::assertStringContainsString('Activate', $output);
        self::assertStringContainsString('value="cast_onboarding_reset_builder"', $output);
        self::assertStringNotContainsString('Setup complete', $output);
        self::assertStringNotContainsString('Finish setup', $output);
        self::assertStringNotContainsString('value="cast_onboarding_complete"', $output);
    }

    public function testReconcilingRenderMustNotMutateStalePersistedWizard(): void
    {
        // Reconciliation is a pure view-model derivation from the server-side
        // plugin state. Rendering must never touch/save the persisted aggregate.
        $this->store->stored = new Wizard(
            state: WizardState::Building,
            step: 'builder_selected',
            selectedBuilder: 'brizy',
            installStatus: InstallStatus::Idle,
        );
        $this->plugins->installed['brizy'] = 'brizy/brizy.php';
        $this->plugins->active = ['brizy/brizy.php'];

        $this->renderPage();

        $stored = $this->store->stored;
        self::assertSame('builder_selected', $stored->step);
        self::assertSame(InstallStatus::Idle, $stored->installStatus);
        self::assertSame('brizy', $stored->selectedBuilder);
        self::assertFalse($this->store->saved, 'Rendering must never call store->save().');
    }

    public function testSkippedScreenOffersReopen(): void
    {
        $this->store->stored = new Wizard(state: WizardState::Skipped);

        $output = $this->renderPage();

        self::assertStringContainsString('value="cast_onboarding_reopen"', $output);
        self::assertStringContainsString('Setup skipped', $output);
    }

    public function testRenderDoesNotPersistOnboardingState(): void
    {
        // Rendering the welcome screen must never mark onboarding complete.
        $this->renderPage();

        self::assertFalse($this->store->saved);
        self::assertSame(WizardState::NotStarted, $this->store->load()->state);
    }

    public function testActiveCandidateRendersCompleteScreenNotDeadEndInstall(): void
    {
        // The selected builder is actually active at runtime; the persisted
        // step is stale (builder_selected). The install screen — with its
        // otherwise-disabled "Active" row — must never be a dead end: the
        // wizard advances straight to Step 3 with usable actions.
        $this->plugins->installed['brizy'] = 'brizy/brizy.php';
        $this->plugins->active[] = 'brizy/brizy.php';
        $this->store->stored = new Wizard(
            state: WizardState::Building,
            step: 'builder_selected',
            selectedBuilder: 'brizy',
            installStatus: InstallStatus::Active,
        );

        $output = $this->renderPage();

        self::assertStringContainsString('Setup complete', $output);
        self::assertStringContainsString('Create your first page', $output);
        self::assertStringContainsString('Finish setup', $output);
        self::assertStringNotContainsString('disabled="disabled"', $output);
        self::assertStringNotContainsString('cast-install-button', $output);
    }

    /* ------------------------- dashboard notice ------------------------- */

    #[DataProvider('activeStates')]
    public function testNoticeAppearsForActiveStates(WizardState $state): void
    {
        $this->store->stored = new Wizard(state: $state);

        $output = $this->renderNotice();

        // The dashboard entrypoint points the user at the Getting Started page.
        self::assertStringContainsString('admin.php?page=' . OnboardingAdminSubscriber::PAGE_SLUG, $output);
        self::assertStringContainsString('Get Started', $output);
        // It surfaces the accessible Skip action too.
        self::assertStringContainsString('Skip', $output);
    }

    #[DataProvider('hiddenStates')]
    public function testNoticeHiddenForFinishedStates(WizardState $state): void
    {
        $this->store->stored = new Wizard(state: $state);

        self::assertSame('', $this->renderNotice());
    }

    public function testNoticeHiddenWhenUserLacksCapability(): void
    {
        $this->context->allowed = false;

        self::assertSame('', $this->renderNotice());
    }

    public function testNoticeSkipControlPostsToAdminPostWithEscapedNonceAndAction(): void
    {
        $output = $this->renderNotice();

        self::assertStringContainsString('admin-post.php', $output);
        self::assertStringContainsString('NONCE_FIELD_cast_onboarding', $output);
        self::assertStringContainsString('value="cast_onboarding_skip"', $output);
    }

    public function testNoticeHiddenOnWizardPage(): void
    {
        // The "Get Started" CTA is a dashboard entrypoint into the wizard. On
        // the wizard's own page it would be redundant (you are already here),
        // and core injects admin_notices output inside the wizard header —
        // between the h1 and the subtitle — inflating it. Suppress it there.
        $this->context->screenId = 'toplevel_page_' . OnboardingAdminSubscriber::PAGE_SLUG;
        $this->store->stored = new Wizard(state: WizardState::NotStarted);

        self::assertSame('', $this->renderNotice());
    }

    public function testNoticeStillShownWhenOnOtherAdminScreen(): void
    {
        // Outside the wizard page (e.g. the dashboard or another screen) the
        // notice must keep rendering as the getting-started entrypoint.
        $this->context->screenId = 'dashboard';
        $this->store->stored = new Wizard(state: WizardState::NotStarted);

        $output = $this->renderNotice();
        self::assertStringContainsString('Get Started', $output);
    }

    public function testNoticeUsesWorkspaceSetupCopyWithWordPressButtons(): void
    {
        $output = $this->renderNotice();

        // The dashboard CTA speaks to the user's own workspace setup rather than
        // internal product branding.
        self::assertStringContainsString('Set up your workspace', $output);

        // Get Started is the decisive primary action in WP admin button terms.
        self::assertMatchesRegularExpression(
            '/<a [^>]*class="button button-primary"[^>]*>Get Started<\/a>/',
            $output,
        );

        // Skip is a deliberate secondary action using the WordPress button class.
        self::assertMatchesRegularExpression(
            '/<button[^>]*class="button"[^>]*>Skip for now<\/button>/',
            $output,
        );

        // Get Started and Skip are grouped and separated as one aligned action
        // row, matching the `.cast-notice-actions` layout the stylesheet targets.
        self::assertStringContainsString('class="cast-notice-actions"', $output);
    }

    /* --------------------------- dashboard setup --------------------------- */

    public function testSetupDashboardRemovesNoisyWidgets(): void
    {
        $this->subscriber->setupDashboard();

        $removed = array_column($GLOBALS['lumeweb_cast_removed_meta_boxes'], 0);
        self::assertContains('dashboard_primary', $removed);
        self::assertContains('dashboard_right_now', $removed);
        self::assertContains('dashboard_activity', $removed);
        self::assertContains('dashboard_quick_press', $removed);
    }

    public function testSetupDashboardPreservesSiteHealthAndSecurityNags(): void
    {
        $this->subscriber->setupDashboard();

        $removed = array_column($GLOBALS['lumeweb_cast_removed_meta_boxes'], 0);
        // Site Health, PHP/browser/security nags and update surfaces must never
        // be removed by the declutter.
        self::assertNotContains('dashboard_site_health', $removed);
        self::assertNotContains('dashboard_php_nag', $removed);
        self::assertNotContains('dashboard_browser_nag', $removed);
    }

    public function testSetupDashboardReplacesDefaultWelcomePanel(): void
    {
        $this->subscriber->setupDashboard();

        $actions = $this->hookCalls('add_action');
        $removals = $this->hookCalls('remove_action');

        // The default core welcome panel is removed...
        self::assertContains('welcome_panel', array_column($removals, 1));
        // ...and replaced with the shared workspace welcome panel.
        self::assertContains('welcome_panel', array_column($actions, 1));
    }

    public function testSetupDashboardDoesNotRegisterWelcomeForFinishedStates(): void
    {
        $this->store->stored = new Wizard(state: WizardState::Completed);

        $this->subscriber->setupDashboard();

        // The default panel is still removed (no confusion with core's own
        // welcome panel for a finished user)...
        $removals = $this->hookCalls('remove_action');
        self::assertContains('welcome_panel', array_column($removals, 1));

        // ...but no empty replacement panel: nothing added to the welcome_panel
        // hook for a user who already finished onboarding.
        $adds = $this->hookCalls('add_action');
        self::assertNotContains('welcome_panel', array_column($adds, 1));
    }

    public function testSetupDashboardSkippedForNonAdmins(): void
    {
        $this->context->allowed = false;

        $this->subscriber->setupDashboard();

        // Non-admins get no dashboard decluttering and no welcome-panel swap,
        // so they can never be handed an empty panel.
        self::assertSame([], $GLOBALS['lumeweb_cast_removed_meta_boxes']);
        self::assertSame([], $this->hookCalls('remove_action'));
        self::assertSame([], $this->hookCalls('add_action'));
    }

    /* ---------------------- dashboard welcome panel ----------------------- */

    public function testDashboardWelcomePanelRendersSharedWelcomeWithContinueAndSkip(): void
    {
        $this->store->stored = new Wizard(state: WizardState::NotStarted);

        $output = $this->renderDashboardWelcome();

        self::assertMatchesRegularExpression('/<section[^>]*class="card cast-welcome-card"/', $output);
        // Get Started posts the protected start action with a nonce.
        self::assertStringContainsString('value="cast_onboarding_start"', $output);
        self::assertStringContainsString('NONCE_FIELD_', $output);
        self::assertMatchesRegularExpression('/class="button button-primary cast-action-full"/', $output);
        // Skip is the deliberate secondary action.
        self::assertMatchesRegularExpression('/class="button cast-action-full"/', $output);
        self::assertStringContainsString('Skip for now', $output);
    }

    public function testDashboardWelcomePanelBuildingShowsContinueLinkNotStart(): void
    {
        // The welcome card must not offer a fresh start form while the wizard
        // is already building; it should route the user straight back into the
        // in-progress wizard instead of posting an invalid start transition.
        $this->store->stored = new Wizard(state: WizardState::Building);

        $output = $this->renderDashboardWelcome();

        self::assertStringNotContainsString('value="cast_onboarding_start"', $output);
        self::assertStringContainsString('Continue setup', $output);
        self::assertStringContainsString('admin.php?page=' . OnboardingAdminSubscriber::PAGE_SLUG, $output);
    }

    public function testDashboardWelcomePanelHiddenForNonAdmin(): void
    {
        $this->context->allowed = false;

        self::assertSame('', $this->renderDashboardWelcome());
    }

    public function testDashboardWelcomePanelHiddenForFinishedStates(): void
    {
        $this->store->stored = new Wizard(state: WizardState::Completed);

        self::assertSame('', $this->renderDashboardWelcome());
    }

    /**
     * @return iterable<string, array{WizardState}>
     */
    public static function activeStates(): iterable
    {
        yield 'not started' => [WizardState::NotStarted];
        yield 'building' => [WizardState::Building];
    }

    /**
     * @return iterable<string, array{WizardState}>
     */
    public static function hiddenStates(): iterable
    {
        yield 'skipped' => [WizardState::Skipped];
        yield 'completed' => [WizardState::Completed];
    }

    private function renderNotice(): string
    {
        ob_start();
        $this->subscriber->renderNotice();
        return (string) ob_get_clean();
    }

    /**
     * The dashboard welcome-panel callback output, or '' when gated off.
     */
    private function renderDashboardWelcome(): string
    {
        ob_start();
        $this->subscriber->renderDashboardWelcome();
        return (string) ob_get_clean();
    }

    /**
     * The add_action/remove_action calls recorded by the test shims.
     *
     * The bootstrap shim records add_action() under type 'action' and
     * remove_action() under type 'remove_action'; we accept the intuitive
     * 'add_action' spelling here too.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function hookCalls(string $type): array
    {
        $type = $type === 'add_action' ? 'action' : $type;

        $out = [];
        foreach ($GLOBALS['lumeweb_cast_hooks'] as $entry) {
            if (($entry[0] ?? null) === $type) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    public function testStartActionPerformsTransitionAndRedirects(): void
    {
        $this->subscriber->start();

        self::assertSame(['workspace-getting-started'], $this->context->redirects);
        self::assertSame(WizardState::Building, $this->store->load()->state);
    }

    /* --------------------------- assets --------------------------- */

    public function testEnqueueStylesAndScriptsOnGettingStartedScreen(): void
    {
        $this->context->screenId = 'toplevel_page_workspace-getting-started';
        $this->subscriber->registerAssets();

        // WordPress core admin styling handles including the WP 7.1 modern
        // `wp-base-styles` design-token sheet + the dedicated wizard stylesheet.
        $styleHandles = array_column($GLOBALS['lumeweb_cast_enqueued_styles'], 0);
        self::assertContains('wp-base-styles', $styleHandles);
        self::assertContains('common', $styleHandles);
        self::assertContains('forms', $styleHandles);
        self::assertContains('dashicons', $styleHandles);
        self::assertContains('cast-admin', $styleHandles);
        foreach ($GLOBALS['lumeweb_cast_enqueued_styles'] as [$handle, $src, $deps]) {
            if ($handle === 'cast-admin') {
                self::assertSame(['wp-base-styles', 'common', 'forms', 'dashicons'], $deps);
                self::assertStringEndsWith('assets/css/cast-admin.css', $src);
            }
        }

        $scriptHandles = array_column($GLOBALS['lumeweb_cast_enqueued_scripts'], 0);
        self::assertContains('updates', $scriptHandles);
        self::assertContains('cast-install', $scriptHandles);
        foreach ($GLOBALS['lumeweb_cast_enqueued_scripts'] as [$handle, $src, $deps]) {
            if ($handle === 'cast-install') {
                self::assertSame(['updates'], $deps);
                self::assertStringEndsWith('assets/js/cast-install.js', $src);
            }
        }
    }

    public function testWelcomeSurfacesRelyOnRenderBlockingSheetWithoutInlineCriticalCss(): void
    {
        // The welcome layout must come from the single render-blocking
        // cast-admin stylesheet — never from a separately-enqueued inline
        // "critical" block. Since cast-admin.css is a render-blocking <link>
        // in the head, the browser cannot paint before it is applied, so the
        // inline block would be dead weight duplicating the same rules.
        foreach (['dashboard', 'toplevel_page_workspace-getting-started'] as $screenId) {
            $this->context->screenId = $screenId;
            $this->subscriber->registerAssets();

            $handles = array_column($GLOBALS['lumeweb_cast_enqueued_styles'], 0);
            self::assertContains('cast-admin', $handles, "cast-admin must load on {$screenId}.");
            self::assertNotContains(
                'cast-critical',
                $handles,
                "The cast-critical inline handle must not be enqueued on {$screenId}.",
            );
        }
    }

    public function testEnqueueStylesOnDashboardButNotInstallerScripts(): void
    {
        $this->context->screenId = 'dashboard';
        $this->subscriber->registerAssets();

        // The full-width welcome panel on the dashboard shares the same modern
        // style sheet as the wizard, so the admin styling handles load there.
        $styleHandles = array_column($GLOBALS['lumeweb_cast_enqueued_styles'], 0);
        self::assertContains('wp-base-styles', $styleHandles);
        self::assertContains('common', $styleHandles);
        self::assertContains('forms', $styleHandles);
        self::assertContains('dashicons', $styleHandles);
        self::assertContains('cast-admin', $styleHandles);

        // But the no-refresh installer script + core updates script stay on the
        // wizard screen only — never on the dashboard.
        self::assertSame([], $GLOBALS['lumeweb_cast_enqueued_scripts']);
        self::assertSame([], $GLOBALS['lumeweb_cast_localized_scripts']);
    }

    public function testEnqueueUsesOnlyShippedNonReactStyleHandles(): void
    {
        $this->context->screenId = 'toplevel_page_workspace-getting-started';
        $this->subscriber->registerAssets();

        // The modern UI must come from shipped, non-React admin style handles —
        // never from a React-only script just to obtain design tokens. The
        // layout is a single render-blocking cast-admin.css link plus the WP
        // core admin style handles; no extra inline-critical handle exists.
        $styleHandles = array_column($GLOBALS['lumeweb_cast_enqueued_styles'], 0);
        foreach ($styleHandles as $handle) {
            self::assertContains(
                $handle,
                ['wp-base-styles', 'common', 'forms', 'dashicons', 'cast-admin'],
                sprintf('Unexpected style handle enqueued: %s', $handle),
            );
        }
    }

    public function testLocalizedPayloadCarriesSelectedRowNonceAndRecordActions(): void
    {
        $this->context->screenId = 'toplevel_page_workspace-getting-started';
        $this->plugins->installed['brizy'] = 'brizy/brizy.php';
        $this->plugins->active[] = 'brizy/brizy.php';
        $this->store->stored = new Wizard(
            state: WizardState::Building,
            step: 'builder_selected',
            selectedBuilder: 'brizy',
            installStatus: InstallStatus::Active,
        );

        $this->subscriber->registerAssets();

        self::assertSame('castInstall', $GLOBALS['lumeweb_cast_localized_scripts'][0][1] ?? null);
        $data = $GLOBALS['lumeweb_cast_localized_scripts'][0][2] ?? [];

        self::assertSame('brizy', $data['rows'][0]['slug']);
        self::assertCount(1, $data['rows']);

        self::assertSame('http://example.test/wp-admin/admin-post.php', $data['adminPostUrl']);
        self::assertSame('NONCE_cast_onboarding', $data['nonce']);
        self::assertSame('cast_onboarding_install_result', $data['actions']['installResult']);
        self::assertSame('cast_onboarding_activate_result', $data['actions']['activateResult']);
    }

    public function testEnqueueSkippedOnUnrelatedScreens(): void
    {
        $this->context->screenId = 'edit.php';
        $this->subscriber->registerAssets();

        self::assertSame([], $GLOBALS['lumeweb_cast_enqueued_scripts']);
        self::assertSame([], $GLOBALS['lumeweb_cast_enqueued_styles']);
        self::assertSame([], $GLOBALS['lumeweb_cast_localized_scripts']);
    }

    public function testEnqueueScriptsSkippedWhenScreenUnknown(): void
    {
        $this->context->screenId = null;
        $this->subscriber->registerAssets();

        self::assertSame([], $GLOBALS['lumeweb_cast_enqueued_scripts']);
        self::assertSame([], $GLOBALS['lumeweb_cast_enqueued_styles']);
    }

    public function testEnqueueScriptsGatedByCapabilityEvenOnScreen(): void
    {
        $this->context->screenId = 'toplevel_page_workspace-getting-started';
        $this->context->allowed = false;

        $this->subscriber->registerAssets();

        self::assertSame([], $GLOBALS['lumeweb_cast_enqueued_scripts']);
        self::assertSame([], $GLOBALS['lumeweb_cast_enqueued_styles']);
    }

    public function testAdminStylesheetEnqueuesWithPluginFolderUrlAndRealCacheBustingVersion(): void
    {
        $this->context->screenId = 'toplevel_page_workspace-getting-started';
        $this->subscriber->registerAssets();

        // The dedicated wizard stylesheet with the real `cast` plugin folder in
        // the URL — the regression shipped /wp-content/plugins/assets/... which
        // resolved to front-page HTML instead of CSS.
        $style = $this->enqueuedStyle('cast-admin');
        self::assertNotNull($style);
        self::assertSame(
            'http://example.test/wp-content/plugins/cast/assets/css/cast-admin.css',
            $style[1],
        );
        // A genuine cache buster (file mtime), never the core WP 7.1 fallback
        // that the no-version enqueue silently produced before.
        self::assertIsString($style[3]);
        self::assertNotSame('', $style[3]);
        self::assertNotSame('0', $style[3]);
        self::assertNotSame('7.1', $style[3]);
        self::assertSame(['wp-base-styles', 'common', 'forms', 'dashicons'], $style[2]);
    }

    public function testInstallScriptEnqueuesWithPluginFolderUrlAndRealCacheBustingVersion(): void
    {
        $this->context->screenId = 'toplevel_page_workspace-getting-started';
        $this->subscriber->registerAssets();

        $script = $this->enqueuedScript('cast-install');
        self::assertNotNull($script);
        self::assertSame(
            'http://example.test/wp-content/plugins/cast/assets/js/cast-install.js',
            $script[1],
        );
        self::assertIsString($script[3]);
        self::assertNotSame('', $script[3]);
        self::assertNotSame('0', $script[3]);
        self::assertSame(['updates'], $script[2]);
    }

    public function testInstallScreenExposesStructuralWizardLayoutAndActions(): void
    {
        $this->store->stored = new Wizard(
            state: WizardState::Building,
            step: 'builder_selected',
            selectedBuilder: 'brizy',
            installStatus: InstallStatus::Idle,
        );

        $output = $this->renderPage();

        // Centered shell with a branded header and a horizontal numbered
        // progress tracker (progress wrapper always renders on a live step).
        self::assertStringContainsString('class="wrap cast-wizard-wrap"', $output);
        self::assertStringContainsString('class="cast-wizard-header"', $output);
        self::assertStringContainsString('class="cast-progress cast-progress-horizontal"', $output);
        self::assertStringContainsString('class="cast-progress-number"', $output);

        // Substantial content card around the install step.
        self::assertMatchesRegularExpression('/class="cast-screen-frame"/', $output);
        self::assertStringContainsString('class="card cast-install-card"', $output);

        // Aligned selected-builder summary instead of a raw status line.
        self::assertStringContainsString('class="cast-build-summary"', $output);
        self::assertStringContainsString('Brizy (Free)', $output);

        // Full-width install action + grouped secondary actions.
        self::assertStringContainsString('class="button button-primary cast-install-button cast-action-full"', $output);
        self::assertStringContainsString('value="cast_onboarding_reset_builder"', $output);
        self::assertStringContainsString('class="cast-secondary-actions"', $output);

        // aria-live status region for the no-refresh installer stays intact.
        self::assertStringContainsString('id="cast-install-live"', $output);
        self::assertStringContainsString('aria-live="polite"', $output);
    }

    /**
     * @return array{0: string, 1: string, 2: list<string>, 3: mixed, 4: string}|null
     */
    private function enqueuedStyle(string $handle): ?array
    {
        foreach ($GLOBALS['lumeweb_cast_enqueued_styles'] as $style) {
            if ($style[0] === $handle) {
                return $style;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string, 2: list<string>, 3: mixed, 4: mixed}|null
     */
    private function enqueuedScript(string $handle): ?array
    {
        foreach ($GLOBALS['lumeweb_cast_enqueued_scripts'] as $script) {
            if ($script[0] === $handle) {
                return $script;
            }
        }

        return null;
    }

    private function renderPage(): string
    {
        ob_start();
        $this->subscriber->render();
        return (string) ob_get_clean();
    }
}
