<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\OnboardingRequestHandler;
use LumeWeb\Cast\Onboarding\InstallStatus;
use LumeWeb\Cast\Onboarding\ResultCode;
use LumeWeb\Cast\Onboarding\WizardService;
use LumeWeb\Cast\Onboarding\WizardState;
use LumeWeb\Cast\PageBuilder\PageBuilderCatalog;
use LumeWeb\Cast\Tests\Unit\Onboarding\FakeWizardStore;
use PHPUnit\Framework\TestCase;

final class OnboardingRequestHandlerTest extends TestCase
{
    private FakeRequestContext $context;
    private FakeWizardStore $store;
    private OnboardingRequestHandler $handler;

    protected function setUp(): void
    {
        $this->context = new FakeRequestContext();
        $this->store = new FakeWizardStore();
        $this->handler = new OnboardingRequestHandler(
            new WizardService($this->store, new PageBuilderCatalog()),
            $this->context,
        );
    }

    public function testStartDeniedWhenCapabilityMissing(): void
    {
        $this->context->allowed = false;

        $this->handler->start('cast_onboarding', 'manage_options', 'workspace-getting-started');

        self::assertSame(1, $this->context->denyCount);
        self::assertSame([], $this->context->redirects);
        self::assertSame(WizardState::NotStarted, $this->store->load()->state);
    }

    public function testStartDeniedWhenNonceInvalid(): void
    {
        $this->context->nonce = 'bad-nonce';
        $this->context->nonceValid = false;

        $this->handler->start('cast_onboarding', 'manage_options', 'workspace-getting-started');

        self::assertSame(1, $this->context->denyCount);
        self::assertSame([], $this->context->redirects);
        self::assertSame(WizardState::NotStarted, $this->store->load()->state);
    }

    public function testAuthorizedStartPersistsAndRedirects(): void
    {
        $this->handler->start('cast_onboarding', 'manage_options', 'workspace-getting-started');

        self::assertSame(0, $this->context->denyCount);
        self::assertSame(['workspace-getting-started'], $this->context->redirects);
        self::assertSame(WizardState::Building, $this->store->load()->state);
    }

    public function testRepeatedStartFromBuildingRedirectsAndStaysBuilding(): void
    {
        $this->handler->start('cast_onboarding', 'manage_options', 'workspace-getting-started');
        $this->context->redirects = [];

        // No exception may bubble to a fatal admin-post page: a user who
        // submits the start action while already building is redirected back
        // to the wizard, which remains in the running state.
        $this->handler->start('cast_onboarding', 'manage_options', 'workspace-getting-started');

        self::assertSame(0, $this->context->denyCount);
        self::assertSame(['workspace-getting-started'], $this->context->redirects);
        self::assertSame(WizardState::Building, $this->store->load()->state);
    }

    public function testInvalidTransitionRedirectsInsteadOfFatal(): void
    {
        // A stale/crafted form that posts a transition not reachable from the
        // current state (here: complete before start) must never bubble a Finite
        // exception to a fatal page — it redirects back through the normal flow.
        $this->handler->complete('cast_onboarding', 'manage_options', 'workspace-getting-started');

        self::assertSame(0, $this->context->denyCount);
        self::assertSame(['workspace-getting-started'], $this->context->redirects);
        self::assertSame(WizardState::NotStarted, $this->store->load()->state);
    }

    public function testSelectBuilderWithAllowlistedSlugPersistsAndRedirects(): void
    {
        $this->handler->start('cast_onboarding', 'manage_options', 'workspace-getting-started');
        $this->context->params['builder'] = 'brizy';

        $this->handler->selectBuilder('cast_onboarding', 'manage_options', 'workspace-getting-started');

        self::assertSame(['workspace-getting-started', 'workspace-getting-started'], $this->context->redirects);
        self::assertSame('brizy', $this->store->load()->selectedBuilder);
    }

    public function testSelectBuilderDeniedWhenSlugNotAllowlisted(): void
    {
        $this->handler->start('cast_onboarding', 'manage_options', 'workspace-getting-started');
        $this->context->params['builder'] = 'bricks';
        $this->context->redirects = [];

        $this->handler->selectBuilder('cast_onboarding', 'manage_options', 'workspace-getting-started');

        self::assertSame(1, $this->context->denyCount);
        self::assertSame([], $this->context->redirects);
        self::assertNull($this->store->load()->selectedBuilder);
    }

    public function testRecordInstallSuccessRecordsResult(): void
    {
        $this->handler->start('cast_onboarding', 'manage_options', 'workspace-getting-started');
        $this->context->params['success'] = '1';

        $this->handler->recordInstall('cast_onboarding', 'manage_options', 'workspace-getting-started');

        $wizard = $this->store->load();
        self::assertSame(InstallStatus::Installed, $wizard->installStatus);
        self::assertSame(ResultCode::InstallSucceeded, $wizard->resultCode);
    }

    public function testRecordInstallFailureRecordsResult(): void
    {
        $this->handler->start('cast_onboarding', 'manage_options', 'workspace-getting-started');
        $this->context->params['success'] = '0';

        $this->handler->recordInstall('cast_onboarding', 'manage_options', 'workspace-getting-started');

        $wizard = $this->store->load();
        self::assertSame(InstallStatus::Failed, $wizard->installStatus);
        self::assertSame(ResultCode::InstallFailed, $wizard->resultCode);
    }

    public function testRecordActivateSuccessRecordsResult(): void
    {
        $this->handler->start('cast_onboarding', 'manage_options', 'workspace-getting-started');
        $this->context->params['success'] = '1';

        $this->handler->recordActivate('cast_onboarding', 'manage_options', 'workspace-getting-started');

        $wizard = $this->store->load();
        self::assertSame(InstallStatus::Active, $wizard->installStatus);
        self::assertSame(ResultCode::ActivateSucceeded, $wizard->resultCode);
    }

    public function testRecordResultDeniedWithoutCapability(): void
    {
        $this->handler->start('cast_onboarding', 'manage_options', 'workspace-getting-started');
        $this->context->params['success'] = '1';
        $this->context->allowed = false;

        $this->handler->recordInstall('cast_onboarding', 'manage_options', 'workspace-getting-started');

        self::assertSame(1, $this->context->denyCount);
        self::assertNull($this->store->load()->resultCode);
    }

    public function testResetBuilderClearsSelectionAndRedirects(): void
    {
        $this->handler->start('cast_onboarding', 'manage_options', 'workspace-getting-started');
        $this->context->params['builder'] = 'brizy';
        $this->handler->selectBuilder('cast_onboarding', 'manage_options', 'workspace-getting-started');
        $this->context->redirects = [];

        $this->handler->resetBuilder('cast_onboarding', 'manage_options', 'workspace-getting-started');

        self::assertSame(['workspace-getting-started'], $this->context->redirects);
        $wizard = $this->store->load();
        self::assertNull($wizard->selectedBuilder);
        self::assertNull($wizard->step);
    }

    public function testResetBuilderDeniedWithoutCapability(): void
    {
        $this->handler->start('cast_onboarding', 'manage_options', 'workspace-getting-started');
        $this->context->allowed = false;

        $this->handler->resetBuilder('cast_onboarding', 'manage_options', 'workspace-getting-started');

        self::assertSame(1, $this->context->denyCount);
        self::assertNull($this->store->load()->selectedBuilder);
    }

    public function testReopenFromSkippedRestartsAndRedirects(): void
    {
        $this->handler->skip('cast_onboarding', 'manage_options', 'workspace-getting-started');
        $this->context->params['_wpnonce'] = $this->context->nonce;
        $this->context->redirects = [];

        $this->handler->reopen('cast_onboarding', 'manage_options', 'workspace-getting-started');

        self::assertSame(['workspace-getting-started'], $this->context->redirects);
        self::assertSame(WizardState::Building, $this->store->load()->state);
        self::assertNull($this->store->load()->selectedBuilder);
    }

    public function testReopenDeniedWithInvalidNonce(): void
    {
        $this->handler->skip('cast_onboarding', 'manage_options', 'workspace-getting-started');
        $this->context->nonceValid = false;

        $this->handler->reopen('cast_onboarding', 'manage_options', 'workspace-getting-started');

        self::assertSame(1, $this->context->denyCount);
        self::assertSame(WizardState::Skipped, $this->store->load()->state);
    }
}
