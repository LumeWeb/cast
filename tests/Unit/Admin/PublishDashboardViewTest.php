<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\PublishAdminBarView;
use LumeWeb\Cast\Admin\PublishDashboardView;
use LumeWeb\Cast\Admin\PublishStatus;
use LumeWeb\Cast\Environment\EnvProblem;
use LumeWeb\Cast\Environment\EnvProblemKind;
use LumeWeb\Cast\Export\RunStage;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Jobs\PublishIdentity;
use LumeWeb\Cast\Jobs\PublishMode;
use LumeWeb\Cast\Jobs\TickConfig;
use PHPUnit\Framework\TestCase;

/**
 * The publish dashboard view model is a pure, deliberate mapping from the
 * JSON-safe {@see PublishStatus} report to the display/action state the
 * template renders. These tests pin that mapping — what label, progress,
 * readiness level and which actions a given status produces — so the template
 * never has to re-derive a decision from raw status fields.
 */
final class PublishDashboardViewTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function makeStatus(array $overrides = []): PublishStatus
    {
        $defaults = [
            'bootstrapIdentityComplete' => true,
            'envProblems' => [],
            'onboardingComplete' => true,
            'hasEligibleContent' => true,
            'mode' => PublishMode::Manual,
            'autoActive' => false,
            'runStatus' => RunStatus::NotStarted,
            'runStage' => RunStage::Idle,
            'progressCount' => 0,
            'pipelineStage' => null,
            'progressTotal' => null,
            'captureDone' => null,
            'rewriteDone' => null,
            'queuedAt' => null,
            'runActive' => false,
            'dirty' => false,
            'superseded' => false,
            'publishCid' => null,
            'lastError' => null,
            'identity' => null,
            'awaitingWebsite' => false,
            'awaitingCid' => null,
        ];
        $merged = array_replace($defaults, $overrides);

        return new PublishStatus(
            bootstrapIdentityComplete: (bool) $merged['bootstrapIdentityComplete'],
            envProblems: $merged['envProblems'],
            onboardingComplete: (bool) $merged['onboardingComplete'],
            hasEligibleContent: (bool) $merged['hasEligibleContent'],
            mode: $merged['mode'],
            autoActive: (bool) $merged['autoActive'],
            runStatus: $merged['runStatus'],
            runStage: $merged['runStage'],
            progressCount: (int) $merged['progressCount'],
            pipelineStage: $merged['pipelineStage'],
            progressTotal: $merged['progressTotal'] === null ? null : (int) $merged['progressTotal'],
            captureDone: $merged['captureDone'] === null ? null : (int) $merged['captureDone'],
            rewriteDone: $merged['rewriteDone'] === null ? null : (int) $merged['rewriteDone'],
            queuedAt: $merged['queuedAt'] === null ? null : (int) $merged['queuedAt'],
            runActive: (bool) $merged['runActive'],
            dirty: (bool) $merged['dirty'],
            superseded: (bool) $merged['superseded'],
            publishCid: $merged['publishCid'],
            lastError: $merged['lastError'],
            identity: $merged['identity'],
            awaitingWebsite: (bool) $merged['awaitingWebsite'],
            awaitingCid: $merged['awaitingCid'] === null ? null : (string) $merged['awaitingCid'],
        );
    }

    private function identity(): PublishIdentity
    {
        return new PublishIdentity(
            websiteId: 'website-1',
            websiteName: 'Example Co',
            ipnsKeyId: 'key-1',
            ipnsKeyName: 'example-co',
            ready: true,
        );
    }

    public function testIdleNeverRanStatusMapsToReadinessAndNoRunState(): void
    {
        $view = PublishDashboardView::fromStatus($this->makeStatus());

        self::assertSame(PublishDashboardView::READINESS_READY, $view->readiness);
        self::assertSame('ok', $view->readinessLevel);

        self::assertSame('idle', $view->runState);
        self::assertSame('No publish yet', $view->runLabel);
        self::assertNull($view->stageLabel);
        self::assertSame(0, $view->progressPercent);
        self::assertSame(0, $view->progressCount);
    }

    public function testIncompleteEnvironmentMapsToConfigurationReadiness(): void
    {
        $problem = new EnvProblem('PORTAL_API_URL', EnvProblemKind::Missing);
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'bootstrapIdentityComplete' => false,
            'envProblems' => [$problem],
        ]));

        self::assertSame(PublishDashboardView::READINESS_CONFIG, $view->readiness);
        self::assertSame('error', $view->readinessLevel);
        self::assertSame('PORTAL_API_URL', $view->envProblems[0]['variable']);
        self::assertSame('missing', $view->envProblems[0]['kind']);

        // No action may be offered before the environment is configured.
        self::assertFalse($view->canStart);
        self::assertFalse($view->canPublishNow);
        self::assertSame('config', $view->adminBar->state);
    }

    public function testIncompleteOnboardingMapsToSetupReadiness(): void
    {
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'onboardingComplete' => false,
        ]));

        self::assertSame(PublishDashboardView::READINESS_SETUP, $view->readiness);
        self::assertSame('warning', $view->readinessLevel);
        self::assertFalse($view->canStart);
        self::assertFalse($view->canPublishNow);
    }

    public function testNoEligibleContentMapsToNoContentReadiness(): void
    {
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'hasEligibleContent' => false,
        ]));

        self::assertSame(PublishDashboardView::READINESS_NO_CONTENT, $view->readiness);
        self::assertSame('note', $view->readinessLevel);
        self::assertFalse($view->canStart);
    }

    public function testQueuedFirstPublishRunMapsToQueuedState(): void
    {
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::NotStarted,
            'runActive' => true,
            'queuedAt' => 5000,
        ]));

        self::assertSame('queued', $view->runState);
        self::assertSame('Queued to publish', $view->runLabel);
        // With a queue-entry timestamp the secondary line is the honest queued
        // ETA derived from the tick cadence constant (never a hardcoded guess):
        // when it starts, never a misleading "0 items processed".
        $cadence = TickConfig::DEFAULT_TICK_INTERVAL_SECONDS;
        self::assertSame('Starting within ' . $cadence . ' seconds — waiting to begin', $view->progressCountLabel);
        self::assertSame('Starting within ' . $cadence . ' seconds', $view->queuedEtaLabel);
        self::assertSame(5000 + $cadence, $view->queuedEtaStartAt);
        self::assertSame(5000, $view->queuedAt);
        self::assertTrue($view->canCancel);
        self::assertFalse($view->canStart);
        self::assertFalse($view->canPublishNow);
    }

    public function testQueuedProgressCountLabelIsHonestNotANoOpCount(): void
    {
        // A queued (not-started) run has processed nothing by definition: the
        // copy must say so plainly, never the misleading "0 items processed".
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::NotStarted,
            'runActive' => true,
            'progressCount' => 0,
        ]));

        self::assertSame('Waiting to begin', $view->progressCountLabel);

        // A running run names its fine stage: without any discover total yet
        // the honest line is "preparing", never a cumulative item count.
        $preparing = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
            'progressCount' => 42,
        ]));
        self::assertSame('Preparing to export…', $preparing->progressCountLabel);

        // Once discovery has a denominator the live stage verb replaces the
        // cumulative counter.
        $capturing = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
            'progressCount' => 42,
            'pipelineStage' => 'capture',
            'progressTotal' => 100,
        ]));
        self::assertSame('Capturing content… (100 URLs)', $capturing->progressCountLabel);
    }

    public function testQueuedEtaDerivesFromTheTickCadenceConstant(): void
    {
        $cadence = TickConfig::DEFAULT_TICK_INTERVAL_SECONDS;
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::NotStarted,
            'runActive' => true,
            'queuedAt' => 9000,
        ]));

        // The server label, the expected-start anchor and the secondary
        // progress line all derive from the SAME cadence constant, so they can
        // never disagree about "when it starts" — and stay truthful if the
        // deployment cadence ever changes.
        self::assertSame('Starting within ' . $cadence . ' seconds', $view->queuedEtaLabel);
        self::assertSame(9000 + $cadence, $view->queuedEtaStartAt);
        self::assertSame('Starting within ' . $cadence . ' seconds — waiting to begin', $view->progressCountLabel);
        self::assertGreaterThanOrEqual(1, $cadence, 'a sub-second cadence would make the ETA meaningless');
    }

    public function testQueuedEtaIsNullWithoutAQueueEntryTimestamp(): void
    {
        // A queued run without a queue-entry time cannot promise a truthful
        // window: the ETA fields stay null and the honest plain waiting copy
        // stands alone.
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::NotStarted,
            'runActive' => true,
        ]));

        self::assertSame('queued', $view->runState);
        self::assertNull($view->queuedEtaLabel);
        self::assertNull($view->queuedEtaStartAt);
        self::assertSame('Waiting to begin', $view->progressCountLabel);
    }

    public function testQueuedEtaFieldsOnlyExistForTheQueuedState(): void
    {
        // A running/finished run is not waiting to start: no ETA. The live run
        // names its fine stage with the discover denominator; the finished run
        // keeps the legacy processed-item count.
        $running = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
            'queuedAt' => 9000,
            'progressCount' => 7,
            'pipelineStage' => 'rewrite',
            'progressTotal' => 60,
        ]));
        self::assertNull($running->queuedEtaLabel);
        self::assertNull($running->queuedEtaStartAt);
        self::assertSame('Rewriting content… (60 URLs)', $running->progressCountLabel);

        $completed = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Completed,
            'runStage' => RunStage::Finished,
            'queuedAt' => 9000,
            'progressCount' => 12,
        ]));
        self::assertNull($completed->queuedEtaLabel);
        self::assertNull($completed->queuedEtaStartAt);
        self::assertSame('12 items processed', $completed->progressCountLabel);
    }

    public function testProgressCountLabelIsIndeterminateBeforeDiscoveryHasATotal(): void
    {
        // Before discovery drains there is no denominator, so the export line
        // names the fine stage honestly instead of a fabricated percent/count.
        $probe = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
            'pipelineStage' => 'probe',
        ]));
        self::assertSame('Preparing to export…', $probe->progressCountLabel);

        $setup = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
            'pipelineStage' => 'setup',
        ]));
        self::assertSame('Preparing to export…', $setup->progressCountLabel);

        // A freshly started run whose first tick has not pinned a cursor also
        // reads as preparing — never a misleading count.
        $notStartedYet = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Idle,
            'runActive' => true,
        ]));
        self::assertSame('Preparing to export…', $notStartedYet->progressCountLabel);

        // The discover stage is running — still no total — so it is discovering.
        $discovering = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
            'pipelineStage' => 'discover',
        ]));
        self::assertSame('Discovering content…', $discovering->progressCountLabel);
    }

    public function testProgressCountLabelUsesTheStageVerbBeforeStageCompletion(): void
    {
        // While capture runs its summary has not landed, so the honest line is
        // the stage verb plus the discover total — never the cumulative count.
        $capturing = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
            'pipelineStage' => 'capture',
            'progressTotal' => 120,
        ]));
        self::assertSame('Capturing content… (120 URLs)', $capturing->progressCountLabel);

        // Same restraint on rewrite.
        $rewriting = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
            'pipelineStage' => 'rewrite',
            'progressTotal' => 120,
        ]));
        self::assertSame('Rewriting content… (120 URLs)', $rewriting->progressCountLabel);

        // pack/wrapup name the build step with the same honest denominator.
        $packing = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Uploading,
            'runActive' => true,
            'pipelineStage' => 'pack',
            'progressTotal' => 120,
        ]));
        self::assertSame('Building the export (120 URLs)', $packing->progressCountLabel);

        $wrapping = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Publishing,
            'runActive' => true,
            'pipelineStage' => 'wrapup',
            'progressTotal' => 120,
        ]));
        self::assertSame('Building the export (120 URLs)', $wrapping->progressCountLabel);
    }

    public function testProgressCountLabelShowsTheTerminalXOfMOnceTheStageSummaryLands(): void
    {
        // The capture summary totals every terminally-recorded row (done +
        // failed + skipped); only once it exists is the honest cap-sum real.
        $captured = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
            'pipelineStage' => 'capture',
            'progressTotal' => 120,
            'captureDone' => 118,
        ]));
        self::assertSame('Captured 118 of 120 URLs', $captured->progressCountLabel);

        // The rewrite summary totals rewritten + passed-through rows.
        $rewritten = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
            'pipelineStage' => 'rewrite',
            'progressTotal' => 120,
            'rewriteDone' => 90,
        ]));
        self::assertSame('Rewrote 90 of 120', $rewritten->progressCountLabel);

        // A live 0 (no rows terminal yet while capture runs) is honest and
        // moving — the x-of-M line renders, never the frozen stage verb.
        $noneYet = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
            'pipelineStage' => 'capture',
            'progressTotal' => 120,
            'captureDone' => 0,
        ]));
        self::assertSame('Captured 0 of 120 URLs', $noneYet->progressCountLabel);
    }

    public function testProgressCountLabelHonorsTheFullWorkDenominatorIncludingCollectedAssets(): void
    {
        // The service now reports the FULL denominator (discover-enqueued URLs
        // PLUS collected assets, e.g. 15 discovered + 2 collected = 17). When
        // rewrite has rewritten every one of those rows the numerator may
        // legitimately reach the total — "Rewrote 17 of 17" — and the view
        // renders that equal state, never an x-over-M.
        $rewritten = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
            'pipelineStage' => 'rewrite',
            'progressTotal' => 17,
            'rewriteDone' => 17,
        ]));
        self::assertSame('Rewrote 17 of 17', $rewritten->progressCountLabel);

        // The capture side reaches the same equal state: the numerator can
        // reach, never exceed, the discover-only total while capture runs.
        $captured = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
            'pipelineStage' => 'capture',
            'progressTotal' => 15,
            'captureDone' => 15,
        ]));
        self::assertSame('Captured 15 of 15 URLs', $captured->progressCountLabel);
    }

    public function testProgressCountLabelCoversTheCoarseTailAndFinishedStates(): void
    {
        // A full run at the publish boundary (total present) shows the tail
        // publishing copy, never a fake count.
        $publishing = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Publishing,
            'runActive' => true,
            'pipelineStage' => 'publish',
            'progressTotal' => 120,
        ]));
        self::assertSame('Publishing…', $publishing->progressCountLabel);

        // The coarse upload fallback keeps the total in view.
        $uploading = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Uploading,
            'runActive' => true,
            'pipelineStage' => 'publish',
            'progressTotal' => 120,
        ]));
        self::assertSame('Uploading… (120 URLs)', $uploading->progressCountLabel);

        // Finished/terminal copy is deliberately unchanged.
        $completed = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Completed,
            'runStage' => RunStage::Finished,
            'progressCount' => 12,
        ]));
        self::assertSame('12 items processed', $completed->progressCountLabel);

        $cancelled = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Cancelled,
            'runStage' => RunStage::Finished,
            'progressCount' => 3,
        ]));
        self::assertSame('3 items processed', $cancelled->progressCountLabel);
    }

    public function testProgressCountLabelSuppressesDiscoveryForAPublishOnlyRerun(): void
    {
        // A publish-only re-run resumes at 'publish|' with no discover total:
        // it never re-exported, so the line must say it is republishing rather
        // than pretending discovery is happening (the no-total rule would
        // otherwise read as "Discovering content…").
        $rerun = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Publishing,
            'runActive' => true,
            'pipelineStage' => 'publish',
            'progressTotal' => null,
        ]));
        self::assertSame('Republishing existing build…', $rerun->progressCountLabel);

        // A full run at the same boundary but WITH a total is a normal publish.
        $full = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Publishing,
            'runActive' => true,
            'pipelineStage' => 'publish',
            'progressTotal' => 85,
        ]));
        self::assertSame('Publishing…', $full->progressCountLabel);
    }

    public function testProgressCountLabelExposesTheNewStatusFields(): void
    {
        // The fine stage and discover total are passed through so the template
        // gets its data hook and the client its mirror denominator.
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
            'pipelineStage' => 'capture',
            'progressTotal' => 100,
        ]));

        self::assertSame('capture', $view->pipelineStage);
        self::assertSame(100, $view->progressTotal);
    }

    public function testQueuedEscapeIsOfferedForAReadyFirstPublish(): void
    {
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::NotStarted,
            'runActive' => true,
            'identity' => null,
        ]));

        self::assertTrue($view->canStartNowEscape);
        self::assertSame('start', $view->escapeAction);
    }

    public function testQueuedEscapeUsesNowRouteOnceIdentityExists(): void
    {
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::NotStarted,
            'runActive' => true,
            'identity' => $this->identity(),
        ]));

        self::assertTrue($view->canStartNowEscape);
        self::assertSame('now', $view->escapeAction);
    }

    public function testQueuedEscapeIsWithheldWhenTheSurfaceIsNotReady(): void
    {
        // Env problems / onboarding / missing content all make the escape
        // unavailable: the same ready surface every publish route hard
        // requires. A superseded queued run must never be kicked either.
        self::assertFalse(PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::NotStarted,
            'runActive' => true,
            'bootstrapIdentityComplete' => false,
            'envProblems' => [new EnvProblem('PORTAL_API_URL', EnvProblemKind::Missing)],
        ]))->canStartNowEscape);

        self::assertFalse(PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::NotStarted,
            'runActive' => true,
            'onboardingComplete' => false,
        ]))->canStartNowEscape);

        self::assertFalse(PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::NotStarted,
            'runActive' => true,
            'hasEligibleContent' => false,
        ]))->canStartNowEscape);

        self::assertFalse(PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::NotStarted,
            'runActive' => true,
            'superseded' => true,
        ]))->canStartNowEscape);

        // A run that is not queued (idle/running) never offers the escape.
        self::assertFalse(PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
        ]))->canStartNowEscape);

        self::assertNull(PublishDashboardView::fromStatus($this->makeStatus())->escapeAction);
    }

    public function testRunningExportMapsStageProgressAndLabel(): void
    {
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'progressCount' => 42,
            'runActive' => true,
        ]));

        self::assertSame('running', $view->runState);
        self::assertSame('Exporting content', $view->runLabel);
        self::assertSame('Exporting content', $view->stageLabel);
        self::assertSame(25, $view->progressPercent);
        self::assertSame(42, $view->progressCount);
        self::assertTrue($view->canCancel);
    }

    public function testPublishStageProgressPercent(): void
    {
        self::assertSame(55, PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Uploading,
            'runActive' => true,
        ]))->progressPercent);

        self::assertSame(85, PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Publishing,
            'runActive' => true,
        ]))->progressPercent);
    }

    public function testPausedRunMapsToPausedState(): void
    {
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Paused,
            'runActive' => true,
        ]));

        self::assertSame('paused', $view->runState);
        self::assertSame('Paused', $view->runLabel);
        self::assertTrue($view->canCancel);
    }

    public function testCompletedRunMapsToPublished(): void
    {
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Completed,
            'runStage' => RunStage::Finished,
            'publishCid' => 'QmExampleCid',
            'identity' => $this->identity(),
        ]));

        self::assertSame('completed', $view->runState);
        self::assertSame('Published', $view->runLabel);
        self::assertSame('QmExampleCid', $view->publishCid);
        self::assertSame('Example Co', $view->websiteName);
    }

    public function testCompletedWithWarningsSurface(): void
    {
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::CompletedWithWarnings,
            'runStage' => RunStage::Finished,
            'identity' => $this->identity(),
        ]));

        self::assertSame('completed_with_warnings', $view->runState);
        self::assertSame('Published with warnings', $view->runLabel);
    }

    public function testFailedRunCarriesTheActionableError(): void
    {
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Failed,
            'runStage' => RunStage::Finished,
            'lastError' => 'The publish boundary could not reach Pinner.',
        ]));

        self::assertSame('failed', $view->runState);
        self::assertSame('Publish failed', $view->runLabel);
        self::assertSame('The publish boundary could not reach Pinner.', $view->lastError);
    }

    public function testCancelledRunMapsToCancelled(): void
    {
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Cancelled,
            'runStage' => RunStage::Finished,
        ]));

        self::assertSame('cancelled', $view->runState);
        self::assertSame('Cancelled', $view->runLabel);
        self::assertFalse($view->canCancel);
    }

    public function testExplicitStateLabelMapsEveryQueueRunState(): void
    {
        // The accessible state chip must expose the full explicit state set:
        // ready, queued (plain copy — never worker jargon), publishing,
        // published, and the honest failed/cancelled retry states.
        $cases = [
            'idle' => 'Ready',
            'queued' => 'Queued — starting shortly',
            'running' => 'Publishing',
            'paused' => 'Paused',
            'completed' => 'Published',
            'completed_with_warnings' => 'Published with warnings',
            'failed' => 'Failed — retry available',
            'cancelled' => 'Cancelled — retry available',
        ];

        $statuses = [
            'idle' => $this->makeStatus(),
            'queued' => $this->makeStatus(['runStatus' => RunStatus::NotStarted, 'runActive' => true]),
            'running' => $this->makeStatus(['runStatus' => RunStatus::Running, 'runStage' => RunStage::Exporting, 'runActive' => true]),
            'paused' => $this->makeStatus(['runStatus' => RunStatus::Paused, 'runActive' => true]),
            'completed' => $this->makeStatus(['runStatus' => RunStatus::Completed, 'runStage' => RunStage::Finished]),
            'completed_with_warnings' => $this->makeStatus(['runStatus' => RunStatus::CompletedWithWarnings, 'runStage' => RunStage::Finished]),
            'failed' => $this->makeStatus(['runStatus' => RunStatus::Failed, 'runStage' => RunStage::Finished]),
            'cancelled' => $this->makeStatus(['runStatus' => RunStatus::Cancelled, 'runStage' => RunStage::Finished]),
        ];

        foreach ($cases as $state => $label) {
            $view = PublishDashboardView::fromStatus($statuses[$state]);
            self::assertSame($state, $view->runState);
            self::assertSame($label, $view->stateLabel, "state label for '{$state}'");
        }
    }

    public function testModeMappingIsExplicitForAvailableModes(): void
    {
        $manual = PublishDashboardView::fromStatus($this->makeStatus(['mode' => PublishMode::Manual]));
        self::assertSame('manual', $manual->mode);
        self::assertFalse($manual->autoActive);

        $onUpdate = PublishDashboardView::fromStatus($this->makeStatus(['mode' => PublishMode::OnUpdate]));
        self::assertSame('on_update', $onUpdate->mode);
        self::assertTrue($onUpdate->autoActive);
    }

    public function testFirstPublishOffersStartActionOnly(): void
    {
        $view = PublishDashboardView::fromStatus($this->makeStatus());

        self::assertTrue($view->canStart);
        self::assertFalse($view->canPublishNow);
        self::assertFalse($view->canCancel);
        self::assertFalse($view->canPublishArtifact);
    }

    public function testFailedFirstPublishRemainsStartable(): void
    {
        // The first-publish deadlock regression: onboarding complete, content
        // ready, no identity yet, and a terminal (failed) run record. The
        // dashboard must keep "Publish to Pinner" enabled because
        // ContentPublishScheduler::startNow() restarts over a terminal slot.
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Failed,
            'runStage' => RunStage::Finished,
            'lastError' => 'The upload timed out',
        ]));

        self::assertSame('failed', $view->runState);
        self::assertTrue($view->canStart);
        self::assertSame('start', $view->primaryAction);
        self::assertTrue($view->needsPublish);
        self::assertFalse($view->canPublishNow);
        self::assertFalse($view->canCancel);
        self::assertSame('Your last publish failed. Publish to Pinner to retry.', $view->contextMessage);
    }

    public function testCancelledFirstPublishRemainsStartable(): void
    {
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Cancelled,
            'runStage' => RunStage::Finished,
        ]));

        self::assertSame('cancelled', $view->runState);
        self::assertTrue($view->canStart);
        self::assertSame('start', $view->primaryAction);
        self::assertTrue($view->needsPublish);
        self::assertFalse($view->canPublishNow);
        self::assertSame('Your last publish was cancelled. Publish to Pinner to start again.', $view->contextMessage);
    }

    public function testFailedFirstPublishStaysDisabledWhenEnvironmentUnavailable(): void
    {
        // The retry must never bypass the environment check: a failed first
        // publish with a now-incomplete env still leaves the action disabled.
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'bootstrapIdentityComplete' => false,
            'envProblems' => [new EnvProblem('PORTAL_API_URL', EnvProblemKind::Missing)],
            'runStatus' => RunStatus::Failed,
            'runStage' => RunStage::Finished,
        ]));

        self::assertSame(PublishDashboardView::READINESS_CONFIG, $view->readiness);
        self::assertFalse($view->canStart);
        self::assertNull($view->primaryAction);
        self::assertFalse($view->needsPublish);
    }

    public function testFailedRepublishOffersPublishNowWithHonestContext(): void
    {
        // A re-publish that failed (identity exists) retries through 'now',
        // and the context names the failure rather than pretending nothing
        // happened.
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Failed,
            'runStage' => RunStage::Finished,
            'identity' => $this->identity(),
        ]));

        self::assertTrue($view->canPublishNow);
        self::assertFalse($view->canStart);
        self::assertSame('now', $view->primaryAction);
        self::assertSame('Your last publish failed. Publish again to retry.', $view->contextMessage);
        self::assertTrue($view->canPublishArtifact);
    }

    public function testActiveRunOwnsContextEvenWhenIdentityMissing(): void
    {
        // Regression: an active (non-terminal) run must never be startable and
        // the run copy must own the context, not the first-publish wording.
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
        ]));

        self::assertFalse($view->canStart);
        self::assertNull($view->primaryAction);
        self::assertSame('Publishing is in progress — progress updates live below.', $view->contextMessage);
    }

    public function testNeverPublishedWithIdentityOffersPublishNow(): void
    {
        $view = PublishDashboardView::fromStatus($this->makeStatus(['identity' => $this->identity()]));

        self::assertFalse($view->canStart);
        self::assertTrue($view->canPublishNow);
        self::assertFalse($view->canPublishArtifact);
    }

    public function testTerminalRunWithIdentityOffersArtifactRepublish(): void
    {
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Failed,
            'runStage' => RunStage::Finished,
            'identity' => $this->identity(),
        ]));

        self::assertTrue($view->canPublishArtifact);
        self::assertTrue($view->canPublishNow);
    }

    public function testActiveRunOffersCancelAndHidesPublishActions(): void
    {
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
            'identity' => $this->identity(),
        ]));

        self::assertTrue($view->canCancel);
        self::assertFalse($view->canStart);
        self::assertFalse($view->canPublishNow);
        self::assertFalse($view->canPublishArtifact);
    }

    public function testCancelVisibilityMirrorsRunActiveAcrossEveryState(): void
    {
        // The single source of truth for the Cancel guard: only a live run
        // (queued/running/paused) may cancel; idle and every terminal state
        // must never offer Cancel. The template omits the button there and the
        // client reconciliation mirrors this exact matrix.
        $cases = [
            'idle' => [RunStatus::NotStarted, false, false],
            'queued' => [RunStatus::NotStarted, true, true],
            'running' => [RunStatus::Running, true, true],
            'paused' => [RunStatus::Paused, true, true],
            'completed' => [RunStatus::Completed, false, false],
            'completed_with_warnings' => [RunStatus::CompletedWithWarnings, false, false],
            'failed' => [RunStatus::Failed, false, false],
            'cancelled' => [RunStatus::Cancelled, false, false],
        ];

        foreach ($cases as $label => [$runStatus, $runActive, $expectCancel]) {
            $view = PublishDashboardView::fromStatus($this->makeStatus([
                'runStatus' => $runStatus,
                'runStage' => $runActive ? RunStage::Exporting : RunStage::Finished,
                'runActive' => $runActive,
            ]));

            self::assertSame(
                $expectCancel,
                $view->canCancel,
                \sprintf('canCancel for the %s state must mirror runActive', $label),
            );
        }
    }

    public function testEscapeIsOnlyOfferedWhileQueued(): void
    {
        // The recovery/start-now escape never exists outside the queued state:
        // idle, running, paused and every terminal state offer no escape, so
        // the escape line cannot appear on a stale card for those states.
        $nonQueued = [
            'idle' => ['runStatus' => RunStatus::NotStarted, 'runActive' => false],
            'running' => ['runStatus' => RunStatus::Running, 'runStage' => RunStage::Exporting, 'runActive' => true],
            'paused' => ['runStatus' => RunStatus::Paused, 'runActive' => true],
            'completed' => ['runStatus' => RunStatus::Completed, 'runStage' => RunStage::Finished, 'runActive' => false],
            'failed' => ['runStatus' => RunStatus::Failed, 'runStage' => RunStage::Finished, 'runActive' => false],
            'cancelled' => ['runStatus' => RunStatus::Cancelled, 'runStage' => RunStage::Finished, 'runActive' => false],
        ];

        foreach ($nonQueued as $label => $overrides) {
            $view = PublishDashboardView::fromStatus($this->makeStatus($overrides));

            self::assertFalse($view->canStartNowEscape, \sprintf('no escape offered for %s', $label));
            self::assertNull($view->escapeAction, \sprintf('no escape action for %s', $label));
        }

        // Only a ready queued run offers the escape.
        self::assertTrue(PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::NotStarted,
            'runActive' => true,
        ]))->canStartNowEscape);
    }

    public function testAwaitingWebsiteMapsToParkedS1CopyWithNoFakeProgress(): void
    {
        // A parked first publish awaiting a website derives the S1 copy across
        // the whole view model: the chip reads as deliberately sent, the copy
        // leads with the parked wait, and no fabricated percentage/count is
        // shown (the template also drops the bar/value while awaiting).
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Paused,
            'runActive' => true,
            'awaitingWebsite' => true,
            'awaitingCid' => 'QmParkedCid',
        ]));

        self::assertTrue($view->awaitingWebsite);
        self::assertSame('QmParkedCid', $view->websiteCid);
        self::assertSame('Sent — waiting for a website', $view->stateLabel);
        self::assertSame(0, $view->progressPercent, 'a parked run shows no progress percentage');
        self::assertSame('Waiting for a website — the run is paused.', $view->progressCountLabel);
        self::assertSame(
            'Your upload was sent. The run is waiting for a website — open Pinner and create one or attach one to this workspace, then come back.',
            $view->contextMessage,
        );

        // No prominent publish action while parked (the operator resolves the
        // website first); Cancel stays the live-run escape.
        self::assertNull($view->primaryAction);
        self::assertFalse($view->canStartNowEscape);
        self::assertTrue($view->canCancel);
    }

    public function testOrdinaryPausedRunKeepsPlainCopyWhenNotAwaiting(): void
    {
        // The awaiting copy only wins while the flag is actually set: a plain
        // paused run keeps the standard chip and the plain paused context.
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Paused,
            'runActive' => true,
        ]));

        self::assertFalse($view->awaitingWebsite);
        self::assertNull($view->websiteCid);
        self::assertSame('Paused', $view->stateLabel);
        self::assertSame('Publishing is paused.', $view->contextMessage);
    }

    public function testTerminalStatesHideCancelAndOfferTheRetryPrimaryAction(): void
    {
        // A cancelled/failed first publish (no identity) re-arms the first-
        // publish 'start' retry; once an identity exists the terminal record
        // retries through 'now'. Cancel is never offered. The completed-without-
        // identity record is an anomaly (completing requires an identity) and
        // stays unactionable — it is not a restartable slot.
        $restartableFirstPublish = [RunStatus::Failed, RunStatus::Cancelled];
        foreach ($restartableFirstPublish as $runStatus) {
            $view = PublishDashboardView::fromStatus($this->makeStatus([
                'runStatus' => $runStatus,
                'runStage' => RunStage::Finished,
                'runActive' => false,
            ]));

            self::assertFalse($view->canCancel, 'no Cancel for a terminal first publish');
            self::assertSame('start', $view->primaryAction, 'terminal first publish re-arms the start retry');
        }

        $terminalWithIdentity = [RunStatus::Failed, RunStatus::Cancelled, RunStatus::Completed];
        foreach ($terminalWithIdentity as $runStatus) {
            $view = PublishDashboardView::fromStatus($this->makeStatus([
                'runStatus' => $runStatus,
                'runStage' => RunStage::Finished,
                'runActive' => false,
                'identity' => $this->identity(),
            ]));

            self::assertFalse($view->canCancel, 'no Cancel for a terminal re-publish');
            self::assertSame('now', $view->primaryAction, 'terminal re-publish re-arms the now retry');
        }
    }

    public function testDriftAndSupersededFlagsSurface(): void
    {
        $view = PublishDashboardView::fromStatus($this->makeStatus([
            'dirty' => true,
            'superseded' => true,
            'identity' => $this->identity(),
        ]));

        self::assertTrue($view->dirty);
        self::assertTrue($view->superseded);
    }

    public function testAdminBarWorkingStateWhileRunIsActive(): void
    {
        $bar = PublishAdminBarView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Publishing,
            'runActive' => true,
        ]));

        self::assertSame('working', $bar->state);
        self::assertFalse($bar->canAct);
        self::assertNull($bar->action);
    }

    public function testAdminBarFailedStateWithRetryAction(): void
    {
        $bar = PublishAdminBarView::fromStatus($this->makeStatus([
            'runStatus' => RunStatus::Failed,
            'runStage' => RunStage::Finished,
            'lastError' => 'boom',
            'identity' => $this->identity(),
        ]));

        self::assertSame('failed', $bar->state);
        self::assertTrue($bar->canAct);
        self::assertSame('now', $bar->action);
    }

    public function testAdminBarDirtyStateNamesUnpublishedChanges(): void
    {
        $bar = PublishAdminBarView::fromStatus($this->makeStatus([
            'dirty' => true,
            'identity' => $this->identity(),
        ]));

        self::assertSame('dirty', $bar->state);
        self::assertSame('Unpublished changes', $bar->label);
        self::assertTrue($bar->canAct);
        self::assertSame('now', $bar->action);
    }

    public function testAdminBarReadyStateForPublishedSite(): void
    {
        $bar = PublishAdminBarView::fromStatus($this->makeStatus([
            'identity' => $this->identity(),
        ]));

        self::assertSame('ready', $bar->state);
        self::assertSame('Published', $bar->label);
        self::assertTrue($bar->canAct);
        self::assertSame('now', $bar->action);
    }

    public function testAdminBarFirstPublishOffersStartAction(): void
    {
        $bar = PublishAdminBarView::fromStatus($this->makeStatus());

        self::assertSame('ready', $bar->state);
        self::assertTrue($bar->canAct);
        self::assertSame('start', $bar->action);
    }

    public function testAdminBarQuietWithoutContent(): void
    {
        $bar = PublishAdminBarView::fromStatus($this->makeStatus([
            'hasEligibleContent' => false,
        ]));

        self::assertSame('quiet', $bar->state);
        self::assertFalse($bar->canAct);
        self::assertNull($bar->action);
    }
}
