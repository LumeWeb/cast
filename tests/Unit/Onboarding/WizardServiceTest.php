<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Onboarding;

use LumeWeb\Cast\Onboarding\InstallStatus;
use LumeWeb\Cast\Onboarding\InvalidBuilder;
use LumeWeb\Cast\Onboarding\InvalidTransition;
use LumeWeb\Cast\Onboarding\ResultCode;
use LumeWeb\Cast\Onboarding\Wizard;
use LumeWeb\Cast\Onboarding\WizardService;
use LumeWeb\Cast\Onboarding\WizardState;
use LumeWeb\Cast\PageBuilder\PageBuilderCatalog;
use LumeWeb\Cast\Tests\Unit\PageBuilder\FakePluginStateProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WizardServiceTest extends TestCase
{
    private FakeWizardStore $store;
    private WizardService $service;

    protected function setUp(): void
    {
        $this->store = new FakeWizardStore();
        $this->service = new WizardService($this->store, new PageBuilderCatalog());
    }

    public function testCurrentDefaultsToFreshNotStartedWhenNothingStored(): void
    {
        $wizard = $this->service->current();

        self::assertSame(WizardState::NotStarted, $wizard->state);
        self::assertSame(Wizard::SCHEMA_VERSION, $wizard->schemaVersion);
    }

    public function testStartMovesToBuildingAndPersists(): void
    {
        $wizard = $this->service->start();

        self::assertSame(WizardState::Building, $wizard->state);
        self::assertTrue($this->store->saved);
        self::assertSame(WizardState::Building, $this->service->current()->state);
    }

    public function testSkipFromNotStartedReachesSkipped(): void
    {
        $wizard = $this->service->skip();

        self::assertSame(WizardState::Skipped, $wizard->state);
    }

    public function testStartFromSkippedIsRejectedAsTerminal(): void
    {
        $this->service->skip();

        $this->expectException(InvalidTransition::class);

        $this->service->start();
    }

    public function testStartFromBuildingIsIdempotent(): void
    {
        $first = $this->service->start();

        // Re-submitting the start action while the wizard is already running
        // must continue the in-progress flow rather than fail the transition.
        $second = $this->service->start();

        self::assertSame(WizardState::Building, $first->state);
        self::assertSame(WizardState::Building, $second->state);
        self::assertSame(WizardState::Building, $this->service->current()->state);
        self::assertSame($first->updatedAt, $second->updatedAt, 'A repeated start must be a no-op, not a fresh mutation.');
    }

    /**
     * @param list<mixed> $args
     */
    #[DataProvider('invalidFromNotStartedProvider')]
    public function testMutationsRequireValidTransitionFromNotStarted(string $method, array $args): void
    {
        $this->expectException(InvalidTransition::class);

        $this->service->{$method}(...$args);
    }

    /**
     * @return iterable<string, array{string, list<mixed>}>
     */
    public static function invalidFromNotStartedProvider(): iterable
    {
        yield 'complete before start' => ['complete', []];
        yield 'select builder before start' => ['selectBuilder', ['brizy']];
        yield 'install result before start' => ['recordInstall', [true]];
        yield 'activate result before start' => ['recordActivate', [true]];
    }

    public function testCompleteBeforeStartIsRejected(): void
    {
        $this->expectException(InvalidTransition::class);

        $this->service->complete();
    }

    public function testSelectBuilderRecordsBuilderAndStep(): void
    {
        $this->service->start();
        $wizard = $this->service->selectBuilder('generateblocks');

        self::assertSame(WizardState::Building, $wizard->state);
        self::assertSame('generateblocks', $wizard->selectedBuilder);
        self::assertSame('builder_selected', $wizard->step);
        self::assertSame(WizardState::Building, $this->service->current()->state);
    }

    public function testSelectElementorAndBeaverBuilderLiteAcceptsGenericChoice(): void
    {
        $this->service->start();

        foreach (['elementor', 'beaver-builder-lite-version'] as $slug) {
            $wizard = $this->service->selectBuilder($slug);

            self::assertSame($slug, $wizard->selectedBuilder);
            self::assertSame('builder_selected', $wizard->step);
            self::assertSame(WizardState::Building, $wizard->state);
            $this->service->resetBuilder();
        }
    }

    public function testSelectBuilderRejectsSlugNotInCatalogAllowlist(): void
    {
        $this->service->start();

        $this->expectException(InvalidBuilder::class);

        $this->service->selectBuilder('bricks');
    }

    public function testSelectBuilderRejectsArbitraryUnknownSlug(): void
    {
        $this->service->start();

        $this->expectException(InvalidBuilder::class);

        $this->service->selectBuilder('bogus-builder');
    }

    public function testSelectBuilderNativeMapsToReadyStateWithNoInstaller(): void
    {
        $this->service->start();

        $wizard = $this->service->selectBuilder(WizardService::NATIVE_BUILDER);

        self::assertSame(WizardState::Building, $wizard->state);
        self::assertNull($wizard->selectedBuilder);
        self::assertSame('ready', $wizard->step);
    }

    public function testSelectBuilderNativeTokenStillValidatedAgainstCatalog(): void
    {
        // With no catalog injected the native recommendation cannot be resolved,
        // so the token must be rejected rather than trusted as free-form input.
        $bare = new WizardService($this->store);

        $this->expectException(InvalidBuilder::class);

        $bare->selectBuilder(WizardService::NATIVE_BUILDER);
    }

    public function testResetBuilderReturnsToChoiceStateClearingSelection(): void
    {
        $this->service->start();
        $this->service->selectBuilder('brizy');
        $this->service->recordInstall(true);
        $this->service->recordActivate(true);

        $wizard = $this->service->resetBuilder();

        self::assertSame(WizardState::Building, $wizard->state);
        self::assertNull($wizard->selectedBuilder);
        self::assertNull($wizard->step);
        self::assertSame(InstallStatus::Idle, $wizard->installStatus);
        self::assertNull($wizard->resultCode);
    }

    public function testResetBuilderIsRejectedFromNotStarted(): void
    {
        $this->expectException(InvalidTransition::class);

        $this->service->resetBuilder();
    }

    public function testReopenFromSkippedRestartsBuildingCleared(): void
    {
        $this->service->skip();

        $wizard = $this->service->reopen();

        self::assertSame(WizardState::Building, $wizard->state);
        self::assertNull($wizard->selectedBuilder);
        self::assertNull($wizard->step);
        self::assertSame(InstallStatus::Idle, $wizard->installStatus);
        self::assertNull($wizard->resultCode);
    }

    public function testReopenFromCompletedRestartsBuildingCleared(): void
    {
        $this->service->start();
        $this->service->recordInstall(true);
        $this->service->recordActivate(true);
        $this->service->complete();

        $wizard = $this->service->reopen();

        self::assertSame(WizardState::Building, $wizard->state);
        self::assertNull($wizard->selectedBuilder);
        self::assertNull($wizard->step);
        self::assertSame(InstallStatus::Idle, $wizard->installStatus);
    }

    public function testRecordInstallSuccessPersistsResultCode(): void
    {
        $this->service->start();

        $wizard = $this->service->recordInstall(true);

        self::assertSame(InstallStatus::Installed, $wizard->installStatus);
        self::assertSame(ResultCode::InstallSucceeded, $wizard->resultCode);
    }

    public function testRecordInstallFailurePersistsResultCode(): void
    {
        $this->service->start();

        $wizard = $this->service->recordInstall(false);

        self::assertSame(InstallStatus::Failed, $wizard->installStatus);
        self::assertSame(ResultCode::InstallFailed, $wizard->resultCode);
    }

    public function testRecordActivateSuccessAdvancesToCompleteScreenState(): void
    {
        $this->service->start();
        $this->service->selectBuilder('brizy');

        $wizard = $this->service->recordActivate(true);

        // The builder is confirmed active, so the wizard progress moves to the
        // complete screen state — but the aggregate stays Building until the
        // user accepts the Finish action, which moves it to terminal Completed.
        self::assertSame(WizardState::Building, $wizard->state);
        self::assertSame('complete', $wizard->step);
        self::assertSame('brizy', $wizard->selectedBuilder, 'Selected builder is preserved, not cleared.');
        self::assertSame(InstallStatus::Active, $wizard->installStatus);
        self::assertSame(ResultCode::ActivateSucceeded, $wizard->resultCode);
        self::assertSame(WizardState::Building, $this->service->current()->state);
    }

    public function testRecordActivateFailureStaysOnInstallScreenState(): void
    {
        $this->service->start();
        $this->service->selectBuilder('brizy');

        $wizard = $this->service->recordActivate(false);

        self::assertSame(WizardState::Building, $wizard->state);
        self::assertSame('activate_failed', $wizard->step, 'Failed activation must keep the install flow, not complete.');
        self::assertSame('brizy', $wizard->selectedBuilder);
        self::assertSame(InstallStatus::Failed, $wizard->installStatus);
        self::assertSame(ResultCode::ActivateFailed, $wizard->resultCode);
    }

    /**
     * With a PluginStateProvider wired in, the recorded activation outcome is
     * the real server-side active state, NOT the client's claim. A plugin that
     * force-redirects on activation (Brizy's redirectAfterActivation) makes
     * wp.updates report failure even though the plugin activated, so a false
     * "activate_failed" must not be persisted.
     */
    public function testRecordActivateUsesRealServerStateOverClientFailureWhenPluginIsActive(): void
    {
        $provider = new FakePluginStateProvider();
        $provider->installed['brizy'] = 'brizy/brizy.php';
        $provider->active[] = 'brizy/brizy.php';
        $service = new WizardService($this->store, new PageBuilderCatalog(), plugins: $provider);

        $service->start();
        $service->selectBuilder('brizy');

        $wizard = $service->recordActivate(false); // client claims failure (redirect-hijacked)

        self::assertSame(InstallStatus::Active, $wizard->installStatus);
        self::assertSame(ResultCode::ActivateSucceeded, $wizard->resultCode);
        self::assertSame('complete', $wizard->step);
    }

    public function testRecordActivateUsesRealServerStateOverClientSuccessWhenPluginIsNotActive(): void
    {
        $provider = new FakePluginStateProvider();
        $provider->installed['brizy'] = 'brizy/brizy.php'; // installed but NOT active
        $service = new WizardService($this->store, new PageBuilderCatalog(), plugins: $provider);

        $service->start();
        $service->selectBuilder('brizy');

        $wizard = $service->recordActivate(true); // client optimistically claims success

        self::assertSame(InstallStatus::Failed, $wizard->installStatus);
        self::assertSame(ResultCode::ActivateFailed, $wizard->resultCode);
        self::assertSame('activate_failed', $wizard->step);
    }

    public function testCompleteReachesTerminalCompleted(): void
    {
        $this->service->start();

        $wizard = $this->service->complete();

        self::assertSame(WizardState::Completed, $wizard->state);
    }

    public function testAnyMutationAfterCompletionIsRejected(): void
    {
        $this->service->start();
        $this->service->recordInstall(true);
        $this->service->complete();

        $this->expectException(InvalidTransition::class);

        $this->service->skip();
    }

    public function testEveryMutationTouchesUpdatedAt(): void
    {
        $this->service->start();

        self::assertGreaterThan(0, $this->service->current()->updatedAt);
    }
}
