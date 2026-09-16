<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\WizardView;
use LumeWeb\Cast\Onboarding\InstallStatus;
use LumeWeb\Cast\Onboarding\ResultCode;
use LumeWeb\Cast\Onboarding\Wizard;
use LumeWeb\Cast\Onboarding\WizardState;
use LumeWeb\Cast\PageBuilder\PageBuilderCatalog;
use LumeWeb\Cast\Tests\Unit\PageBuilder\FakePluginStateProvider;
use PHPUnit\Framework\TestCase;

final class WizardViewTest extends TestCase
{
    private PageBuilderCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new PageBuilderCatalog();
    }

    public function testNotStartedMapsToWelcomeScreen(): void
    {
        $view = WizardView::fromWizard(Wizard::fresh(), $this->catalog);

        self::assertSame(WizardView::SCREEN_WELCOME, $view->screen);
        self::assertTrue($view->canSkip);
        self::assertFalse($view->canReopen);
        self::assertFalse($view->readyToComplete);
        self::assertTrue($view->canStart);
        self::assertSame(0, $view->progressStep);
        self::assertNull($view->selectedBuilderLabel);
    }

    public function testBuildingStateCannotStartAgain(): void
    {
        // A wizard already underway must never offer the fresh start transition,
        // so the dashboard welcome panel shows a continue path, not a start form.
        $view = WizardView::fromWizard(new Wizard(state: WizardState::Building), $this->catalog);

        self::assertSame(WizardView::SCREEN_CHOOSE, $view->screen);
        self::assertFalse($view->canStart);
    }

    public function testSkippedMapsToSkippedScreenWithReopen(): void
    {
        $view = WizardView::fromWizard(new Wizard(state: WizardState::Skipped), $this->catalog);

        self::assertSame(WizardView::SCREEN_SKIPPED, $view->screen);
        self::assertTrue($view->canReopen);
        self::assertFalse($view->readyToComplete);
    }

    public function testCompletedMapsToCompleteScreenWithReopenAndSelectedLabel(): void
    {
        $view = WizardView::fromWizard(
            new Wizard(state: WizardState::Completed, selectedBuilder: 'brizy'),
            $this->catalog,
        );

        self::assertSame(WizardView::SCREEN_COMPLETE, $view->screen);
        self::assertTrue($view->canReopen);
        self::assertSame(3, $view->progressStep);
        self::assertSame('Brizy (Free)', $view->selectedBuilderLabel);
    }

    public function testBuildingWithoutSelectionMapsToChooseScreen(): void
    {
        $view = WizardView::fromWizard(new Wizard(state: WizardState::Building), $this->catalog);

        self::assertSame(WizardView::SCREEN_CHOOSE, $view->screen);
        self::assertSame(1, $view->progressStep);
        self::assertFalse($view->readyToComplete);
        self::assertTrue($view->canSkip);
    }

    public function testBuildingWithSelectedPluginMapsToInstallScreenNotReady(): void
    {
        $view = WizardView::fromWizard(
            new Wizard(
                state: WizardState::Building,
                step: 'builder_selected',
                selectedBuilder: 'brizy',
                installStatus: InstallStatus::Idle,
            ),
            $this->catalog,
        );

        self::assertSame(WizardView::SCREEN_INSTALL, $view->screen);
        self::assertSame(2, $view->progressStep);
        self::assertSame('brizy', $view->selectedBuilderSlug);
        self::assertSame('Brizy (Free)', $view->selectedBuilderLabel);
        self::assertFalse($view->readyToComplete);
    }

    public function testNativeSelectionMapsToInstallScreenReadyToComplete(): void
    {
        $view = WizardView::fromWizard(
            new Wizard(state: WizardState::Building, step: 'ready'),
            $this->catalog,
        );

        self::assertSame(WizardView::SCREEN_INSTALL, $view->screen);
        self::assertNull($view->selectedBuilderSlug);
        self::assertSame('Native Gutenberg / Site Editor', $view->selectedBuilderLabel);
        self::assertTrue($view->readyToComplete);
    }

    public function testActivatedBuilderMapsToCompleteScreen(): void
    {
        $view = WizardView::fromWizard(
            new Wizard(
                state: WizardState::Building,
                step: 'complete',
                selectedBuilder: 'generateblocks',
                installStatus: InstallStatus::Active,
                resultCode: ResultCode::ActivateSucceeded,
            ),
            $this->catalog,
        );

        self::assertSame(WizardView::SCREEN_COMPLETE, $view->screen);
        self::assertSame(3, $view->progressStep);
        self::assertTrue($view->canFinish);
        self::assertTrue($view->readyToComplete);
        self::assertFalse($view->canReopen);
        self::assertSame('generateblocks', $view->selectedBuilderSlug);
        self::assertSame('GenerateBlocks (Free)', $view->selectedBuilderLabel);
    }

    public function testActivateFailureMapsToInstallScreenNotReady(): void
    {
        $view = WizardView::fromWizard(
            new Wizard(
                state: WizardState::Building,
                step: 'activate_failed',
                selectedBuilder: 'brizy',
                installStatus: InstallStatus::Failed,
                resultCode: ResultCode::ActivateFailed,
            ),
            $this->catalog,
        );

        self::assertSame(WizardView::SCREEN_INSTALL, $view->screen);
        self::assertSame(2, $view->progressStep);
        self::assertFalse($view->readyToComplete);
        self::assertFalse($view->canFinish);
        self::assertSame(ResultCode::ActivateFailed, $view->resultCode);
    }

    public function testTerminalCompletedMapsToCompleteScreenWithoutFinish(): void
    {
        $view = WizardView::fromWizard(
            new Wizard(
                state: WizardState::Completed,
                step: 'complete',
                selectedBuilder: 'brizy',
                installStatus: InstallStatus::Active,
            ),
            $this->catalog,
        );

        self::assertSame(WizardView::SCREEN_COMPLETE, $view->screen);
        self::assertSame(3, $view->progressStep);
        self::assertTrue($view->canReopen);
        self::assertFalse($view->canFinish);
    }

    public function testNativeSelectionReachesCompleteWithoutPluginInstallation(): void
    {
        // Native editor has nothing to install: it lands on the install-ready
        // screen and may be completed straight into the Complete screen.
        $ready = WizardView::fromWizard(new Wizard(state: WizardState::Building, step: 'ready'), $this->catalog);

        self::assertSame(WizardView::SCREEN_INSTALL, $ready->screen);
        self::assertTrue($ready->readyToComplete);

        $completed = WizardView::fromWizard(new Wizard(state: WizardState::Completed, step: 'complete'), $this->catalog);

        self::assertSame(WizardView::SCREEN_COMPLETE, $completed->screen);
        self::assertSame('Native Gutenberg / Site Editor', $completed->selectedBuilderLabel);
    }

    public function testFailedInstallMapsToInstallScreenNotReadyWithResultCode(): void
    {
        $view = WizardView::fromWizard(
            new Wizard(
                state: WizardState::Building,
                step: 'install_failed',
                selectedBuilder: 'brizy',
                installStatus: InstallStatus::Failed,
                resultCode: ResultCode::InstallFailed,
            ),
            $this->catalog,
        );

        self::assertSame(WizardView::SCREEN_INSTALL, $view->screen);
        self::assertFalse($view->readyToComplete);
        self::assertSame(ResultCode::InstallFailed, $view->resultCode);
    }

    public function testChoiceRowsAreCatalogDrivenWithNativeFirst(): void
    {
        $rows = WizardView::choiceRows($this->catalog, 'native');

        self::assertSame(
            ['native', 'brizy', 'generateblocks', 'elementor', 'beaver-builder-lite-version'],
            array_column($rows, 'value'),
        );
        self::assertTrue($rows[0]['isRecommended']);
        self::assertTrue($rows[0]['isCore']);
        self::assertSame('Brizy (Free)', $rows[1]['label']);
        self::assertSame('GenerateBlocks (Free)', $rows[2]['label']);
        self::assertSame('Elementor (Free)', $rows[3]['label']);
        self::assertSame('Beaver Builder Lite (Free)', $rows[4]['label']);

        // Every selectable candidate carries honest free/paid and lock-in /
        // performance notes generically, so no template branch is needed.
        foreach ($rows as $row) {
            self::assertArrayHasKey('isFree', $row);
            self::assertArrayHasKey('costNote', $row);
            self::assertArrayHasKey('lockInNote', $row);
            self::assertArrayHasKey('performanceNote', $row);
            self::assertNotSame('', $row['costNote']);
            self::assertNotSame('', $row['lockInNote']);
            self::assertNotSame('', $row['performanceNote']);
        }
    }

    public function testResultMessageOnlyAppliesToFailureCodes(): void
    {
        self::assertNull(WizardView::resultMessage(null));
        self::assertNull(WizardView::resultMessage(ResultCode::InstallSucceeded));
        self::assertNull(WizardView::resultMessage(ResultCode::ActivateSucceeded));
        self::assertNotNull(WizardView::resultMessage(ResultCode::InstallFailed));
        self::assertNotNull(WizardView::resultMessage(ResultCode::ActivateFailed));
    }

    /* --------------------- runtime plugin-state reconciliation --------------------- */

    public function testStalePersistedInstallStateResolvesToCompleteScreenWhenPluginActive(): void
    {
        // The persisted wizard still says "builder selected / idle" while the
        // plugin is actually active at runtime (a prior redirect/error left the
        // aggregate stale). The server-side provider is authoritative: the
        // rendered wizard must advance to Step 3 regardless of the stale step.
        $plugins = new FakePluginStateProvider();
        $plugins->installed['brizy'] = 'brizy/brizy.php';
        $plugins->active = ['brizy/brizy.php'];

        $view = WizardView::fromWizard(
            new Wizard(
                state: WizardState::Building,
                step: 'builder_selected',
                selectedBuilder: 'brizy',
                installStatus: InstallStatus::Idle,
            ),
            $this->catalog,
            $plugins,
        );

        self::assertSame(WizardView::SCREEN_COMPLETE, $view->screen);
        self::assertSame(3, $view->progressStep);
        self::assertTrue($view->readyToComplete);
        self::assertTrue($view->canFinish);
        self::assertFalse($view->canReopen);
        self::assertSame('brizy', $view->selectedBuilderSlug);
        self::assertSame('Brizy (Free)', $view->selectedBuilderLabel);
    }

    public function testStalePersistedAttemptFailureResolvesToCompleteScreenWhenPluginActive(): void
    {
        // A prior activation redirect/error recorded install_failed even though
        // the plugin actually activated: the runtime state decides the screen.
        $plugins = new FakePluginStateProvider();
        $plugins->installed['brizy'] = 'brizy/brizy.php';
        $plugins->active = ['brizy/brizy.php'];

        $view = WizardView::fromWizard(
            new Wizard(
                state: WizardState::Building,
                step: 'install_failed',
                selectedBuilder: 'brizy',
                installStatus: InstallStatus::Failed,
                resultCode: ResultCode::InstallFailed,
            ),
            $this->catalog,
            $plugins,
        );

        self::assertSame(WizardView::SCREEN_COMPLETE, $view->screen);
        self::assertSame(3, $view->progressStep);
        self::assertTrue($view->canFinish);
    }

    public function testInactivePluginNeverReachesCompleteEvenWithPersistedActiveState(): void
    {
        // The plugin is NOT active at runtime (deactivated, or the install was
        // rolled back). A stale persisted "active/complete" must not be trusted:
        // the wizard stays on Install with a retry, never the Complete screen.
        $plugins = new FakePluginStateProvider();
        $plugins->installed['brizy'] = 'brizy/brizy.php';
        $plugins->active = [];

        $view = WizardView::fromWizard(
            new Wizard(
                state: WizardState::Building,
                step: 'complete',
                selectedBuilder: 'brizy',
                installStatus: InstallStatus::Active,
                resultCode: ResultCode::ActivateSucceeded,
            ),
            $this->catalog,
            $plugins,
        );

        self::assertSame(WizardView::SCREEN_INSTALL, $view->screen);
        self::assertSame(2, $view->progressStep);
        self::assertFalse($view->readyToComplete);
        self::assertFalse($view->canFinish);
    }

    public function testReconciliationIsGenericAcrossCatalogBuilders(): void
    {
        // Elementor, Beaver, GenerateBlocks and Brizy all reconcile through the
        // exact same catalog/provider path — never a builder-name branch.
        foreach (['brizy', 'generateblocks', 'elementor', 'beaver-builder-lite-version'] as $slug) {
            $plugins = new FakePluginStateProvider();
            $basename = $slug . '/' . basename($slug) . '.php';
            $plugins->installed[$slug] = $basename;
            $plugins->active = [$basename];

            $view = WizardView::fromWizard(
                new Wizard(
                    state: WizardState::Building,
                    step: 'builder_selected',
                    selectedBuilder: $slug,
                    installStatus: InstallStatus::Idle,
                ),
                $this->catalog,
                $plugins,
            );

            self::assertSame(WizardView::SCREEN_COMPLETE, $view->screen, sprintf('%s must reconcile to Complete.', $slug));
            self::assertSame(3, $view->progressStep);
            self::assertTrue($view->readyToComplete);
            self::assertTrue($view->canFinish);
        }
    }

    public function testNonInstallableBuilderIsNeverReconciledToComplete(): void
    {
        // kadence-blocks is a known catalog entry but is not installable. Even
        // a matching active plugin directory must never advance it to Complete;
        // unknown/non-installable builders stay rejected on the Install screen.
        $plugins = new FakePluginStateProvider();
        $plugins->installed['kadence-blocks'] = 'kadence-blocks/init.php';
        $plugins->active = ['kadence-blocks/init.php'];

        $view = WizardView::fromWizard(
            new Wizard(
                state: WizardState::Building,
                step: 'builder_selected',
                selectedBuilder: 'kadence-blocks',
                installStatus: InstallStatus::Idle,
            ),
            $this->catalog,
            $plugins,
        );

        self::assertSame(WizardView::SCREEN_INSTALL, $view->screen);
        self::assertSame(2, $view->progressStep);
        self::assertFalse($view->readyToComplete);
        self::assertFalse($view->canFinish);
    }

    public function testNativeSelectionStaysReadyToCompleteWithProviderPresent(): void
    {
        // The native editor has no plugin to reconcile: with a provider wired
        // it still lands on the install-ready screen, never the Complete one.
        $plugins = new FakePluginStateProvider();

        $view = WizardView::fromWizard(
            new Wizard(state: WizardState::Building, step: 'ready'),
            $this->catalog,
            $plugins,
        );

        self::assertSame(WizardView::SCREEN_INSTALL, $view->screen);
        self::assertNull($view->selectedBuilderSlug);
        self::assertTrue($view->readyToComplete);
        self::assertFalse($view->canFinish);
    }
}
