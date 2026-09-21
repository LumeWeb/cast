<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use Closure;
use InvalidArgumentException;
use LumeWeb\Cast\Export\CaptureSummary;
use LumeWeb\Cast\Export\DiscoverResult;
use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\InvalidTransition;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\PackResult;
use LumeWeb\Cast\Export\PackStatus;
use LumeWeb\Cast\Export\ProbeResult;
use LumeWeb\Cast\Export\PublishBoundaryResult;
use LumeWeb\Cast\Export\PublishBoundaryStatus;
use LumeWeb\Cast\Export\RewriteSummary;
use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Export\RunStage;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Export\SetupResult;
use LumeWeb\Cast\Export\WrapupResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExportRunTest extends TestCase
{
    private function settings(): RunSettings
    {
        return new RunSettings(hostname: 'blog.example.test', maxRetries: 3, startCursor: 'post_id:10');
    }

    public function testCreateIsNotStartedAndIdleWithSnapshottedSettings(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);

        self::assertSame('run-1', $run->runId);
        self::assertSame(RunStatus::NotStarted, $run->status);
        self::assertSame(RunStage::Idle, $run->stage);
        self::assertFalse($run->isTerminal());
        self::assertSame(1000, $run->createdAt);
        self::assertSame(1000, $run->updatedAt);
        self::assertSame($this->settings()->toArray(), $run->settingsSnapshot());
    }

    public function testStartSnapshotsSettingsImmutably(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $firstSnapshot = $run->settingsSnapshot();

        // A run created later from different settings cannot disturb this run's
        // frozen snapshot...
        $other = ExportRun::create('run-2', new RunSettings(hostname: 'other.test'), at: 1002);
        $other->start(at: 1003);
        self::assertSame('blog.example.test', $run->settingsSnapshot()['hostname']);

        // ...and the snapshot array is a defensive copy: tampering with the
        // returned array never leaks into the run.
        $tampered = $run->settingsSnapshot();
        $tampered['hostname'] = 'tampered.test';
        self::assertSame('blog.example.test', $run->settingsSnapshot()['hostname']);
        self::assertSame($firstSnapshot, $run->settingsSnapshot());
    }

    public function testStatusMovesThroughLegalLifecycle(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        self::assertSame(RunStatus::NotStarted, $run->status);

        $run->start(at: 1001);
        self::assertSame(RunStatus::Running, $run->status);

        $run->pause(at: 1002);
        self::assertSame(RunStatus::Paused, $run->status);

        $run->resume(at: 1003);
        self::assertSame(RunStatus::Running, $run->status);

        $run->complete(at: 1004);
        self::assertSame(RunStatus::Completed, $run->status);
        self::assertTrue($run->isTerminal());
        self::assertSame(RunStage::Finished, $run->stage);
        self::assertSame(1004, $run->endedAt);
    }

    #[DataProvider('illegalTransitionProvider')]
    public function testIllegalTransitionsThrowInvalidTransition(string $label, Closure $build, Closure $act): void
    {
        $run = $build();

        $this->expectException(InvalidTransition::class);
        $act($run);
    }

    /**
     * @return iterable<string, array{string, Closure(): ExportRun, Closure(ExportRun): void}>
     */
    public static function illegalTransitionProvider(): iterable
    {
        yield 'start twice' => [
            'start twice',
            static fn (): ExportRun => ExportRun::create('r', new RunSettings()),
            static function (ExportRun $run): void {
                $run->start();
                $run->start();
            },
        ];
        yield 'resume while running' => [
            'resume while running',
            static function (): ExportRun {
                $run = ExportRun::create('r', new RunSettings());
                $run->start();

                return $run;
            },
            static function (ExportRun $run): void {
                $run->resume();
            },
        ];
        yield 'complete before start' => [
            'complete before start',
            static fn (): ExportRun => ExportRun::create('r', new RunSettings()),
            static function (ExportRun $run): void {
                $run->complete();
            },
        ];
        yield 'pause before start' => [
            'pause before start',
            static fn (): ExportRun => ExportRun::create('r', new RunSettings()),
            static function (ExportRun $run): void {
                $run->pause();
            },
        ];
        yield 'fail before start' => [
            'fail before start',
            static fn (): ExportRun => ExportRun::create('r', new RunSettings()),
            static function (ExportRun $run): void {
                $run->fail('boom');
            },
        ];
        yield 'cancel from completed' => [
            'cancel from completed',
            static function (): ExportRun {
                $run = ExportRun::create('r', new RunSettings());
                $run->start();
                $run->complete();

                return $run;
            },
            static function (ExportRun $run): void {
                $run->cancel();
            },
        ];
        yield 'start from cancelled' => [
            'start from cancelled',
            static function (): ExportRun {
                $run = ExportRun::create('r', new RunSettings());
                $run->cancel();

                return $run;
            },
            static function (ExportRun $run): void {
                $run->start();
            },
        ];
        yield 'resume from failed' => [
            'resume from failed',
            static function (): ExportRun {
                $run = ExportRun::create('r', new RunSettings());
                $run->start();
                $run->fail('boom');

                return $run;
            },
            static function (ExportRun $run): void {
                $run->resume();
            },
        ];
        yield 'fail from completed' => [
            'fail from completed',
            static function (): ExportRun {
                $run = ExportRun::create('r', new RunSettings());
                $run->start();
                $run->complete();

                return $run;
            },
            static function (ExportRun $run): void {
                $run->fail('boom');
            },
        ];
    }

    public function testStageAdvancesOneStepAtATimeWhileRunning(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);

        $run->advanceStage(RunStage::Exporting, at: 1002);
        self::assertSame(RunStage::Exporting, $run->stage);

        $run->advanceStage(RunStage::Uploading, at: 1003);
        self::assertSame(RunStage::Uploading, $run->stage);

        $run->advanceStage(RunStage::Publishing, at: 1004);
        self::assertSame(RunStage::Publishing, $run->stage);

        $run->advanceStage(RunStage::Finished, at: 1005);
        self::assertSame(RunStage::Finished, $run->stage);
    }

    /**
     * @param Closure(): ExportRun $build
     */
    #[DataProvider('illegalStageMoveProvider')]
    public function testStageCannotSkipRegressOrMoveWhenNotRunning(string $label, Closure $build, RunStage $to): void
    {
        $run = $build();

        $this->expectException(InvalidTransition::class);
        $run->advanceStage($to);
    }

    /**
     * @return iterable<string, array{string, Closure(): ExportRun, RunStage}>
     */
    public static function illegalStageMoveProvider(): iterable
    {
        yield 'skip idle to uploading' => [
            'skip idle to uploading',
            static function (): ExportRun {
                $run = ExportRun::create('r', new RunSettings());
                $run->start();

                return $run;
            },
            RunStage::Uploading,
        ];
        yield 'regress exporting to idle' => [
            'regress exporting to idle',
            static function (): ExportRun {
                $run = ExportRun::create('r', new RunSettings());
                $run->start();
                $run->advanceStage(RunStage::Exporting);

                return $run;
            },
            RunStage::Idle,
        ];
        yield 'advance stage before start' => [
            'advance stage before start',
            static fn (): ExportRun => ExportRun::create('r', new RunSettings()),
            RunStage::Exporting,
        ];
        yield 'advance stage while paused' => [
            'advance stage while paused',
            static function (): ExportRun {
                $run = ExportRun::create('r', new RunSettings());
                $run->start();
                $run->pause();

                return $run;
            },
            RunStage::Exporting,
        ];
        yield 'repeat same stage' => [
            'repeat same stage',
            static function (): ExportRun {
                $run = ExportRun::create('r', new RunSettings());
                $run->start();
                $run->advanceStage(RunStage::Exporting);

                return $run;
            },
            RunStage::Exporting,
        ];
    }

    public function testCompleteWithWarningsRequiresRecordedWarnings(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);

        $this->expectException(InvalidTransition::class);
        $run->completeWithWarnings(at: 1002);
    }

    public function testCompleteWithWarningsMovesToTerminalState(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->recordWarning('Some assets could not be rewritten.', at: 1002);
        $run->recordWarning('A gallery shortcode was skipped.', at: 1003);
        self::assertSame(2, $run->warningCount);

        $run->completeWithWarnings(at: 1004);

        self::assertSame(RunStatus::CompletedWithWarnings, $run->status);
        self::assertTrue($run->isTerminal());
        self::assertSame(RunStage::Finished, $run->stage);
        self::assertSame(1004, $run->endedAt);
        self::assertSame('A gallery shortcode was skipped.', $run->lastWarningMessage);
    }

    public function testFailRecordsErrorAndTerminates(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);

        $run->fail('Portal unreachable', at: 1002);

        self::assertSame(RunStatus::Failed, $run->status);
        self::assertTrue($run->isTerminal());
        self::assertSame(RunStage::Finished, $run->stage);
        self::assertSame('Portal unreachable', $run->lastError);
        self::assertSame(1002, $run->endedAt);
    }

    public function testCancelWorksBeforeStartAndWhileRunningOrPaused(): void
    {
        $beforeStart = ExportRun::create('a', $this->settings(), at: 1000);
        $beforeStart->cancel(at: 1001);
        self::assertSame(RunStatus::Cancelled, $beforeStart->status);
        self::assertSame(RunStage::Finished, $beforeStart->stage);
        self::assertTrue($beforeStart->isTerminal());

        $running = ExportRun::create('b', $this->settings(), at: 1000);
        $running->start(at: 1001);
        $running->cancel(at: 1002);
        self::assertSame(RunStatus::Cancelled, $running->status);

        $paused = ExportRun::create('c', $this->settings(), at: 1000);
        $paused->start(at: 1001);
        $paused->pause(at: 1002);
        $paused->cancel(at: 1003);
        self::assertSame(RunStatus::Cancelled, $paused->status);
    }

    public function testProgressCounterRecordsAndGuardsNonNegative(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        self::assertSame(0, $run->progressCount);

        $run->recordProgress(12, at: 1002);
        self::assertSame(12, $run->progressCount);

        $run->recordProgress(87, at: 1003);
        self::assertSame(87, $run->progressCount);

        $this->expectException(InvalidArgumentException::class);
        $run->recordProgress(-1);
    }

    public function testProgressCannotBeRecordedOnFinishedRun(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->complete(at: 1002);

        $this->expectException(InvalidTransition::class);
        $run->recordProgress(1);
    }

    public function testRetryCountIsBoundedBySettingsMaxRetries(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);

        self::assertSame(3, $run->settings->maxRetries);
        self::assertSame(3, $run->retriesRemaining());
        self::assertFalse($run->retriesExhausted());

        $run->recordRetry(at: 1002);
        $run->recordRetry(at: 1003);
        $run->recordRetry(at: 1004);
        self::assertSame(3, $run->retryCount);
        self::assertSame(0, $run->retriesRemaining());
        self::assertTrue($run->retriesExhausted());

        $this->expectException(InvalidTransition::class);
        $run->recordRetry();
    }

    public function testUploadOffsetAndResumeCursorAreRecorded(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        self::assertNull($run->lastUploadOffsetBytes);
        self::assertSame('', $run->resumeCursor);

        $run->recordUploadOffset(4194304, at: 1002);
        $run->recordResumeCursor('post_id:99', at: 1003);

        self::assertSame(4194304, $run->lastUploadOffsetBytes);
        self::assertSame('post_id:99', $run->resumeCursor);

        $this->expectException(InvalidArgumentException::class);
        $run->recordUploadOffset(-1);
    }

    public function testPublishIdentifiersAreRecordedWhileRunning(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);

        $run->recordUploadIdentifier('tus-uuid-1', at: 1002);
        $run->recordPublishIdentifiers('bafykcid123', 'website-9', 'ipns-k1', at: 1003);

        self::assertSame('tus-uuid-1', $run->lastUploadIdentifier);
        self::assertSame('bafykcid123', $run->publishCid);
        self::assertSame('website-9', $run->websiteId);
        self::assertSame('ipns-k1', $run->ipnsKey);
    }

    public function testPublishIdentifiersCannotBeRecordedOnFinishedRun(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->complete(at: 1002);

        $this->expectException(InvalidTransition::class);
        $run->recordPublishIdentifiers('cid', 'w', 'k');
    }

    public function testNewContentMarksDirtyAndSupersedesActiveRun(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->advanceStage(RunStage::Exporting, at: 1002);

        $run->notifyContentChanged(at: 1003);

        self::assertTrue($run->dirty);
        self::assertTrue($run->superseded);
    }

    public function testSupersededRunMayOnlyCancel(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->advanceStage(RunStage::Exporting, at: 1002);
        $run->notifyContentChanged(at: 1003);

        foreach ($this->blockedAfterSupersede() as $label => $act) {
            $copy = $this->restore($run);
            try {
                $act($copy);
                self::fail('Expected InvalidTransition for ' . $label);
            } catch (InvalidTransition) {
                // expected
            }
        }

        $run->cancel(at: 1004);
        self::assertSame(RunStatus::Cancelled, $run->status);
    }

    public function testSupersededBeforeStartCannotStart(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->notifyContentChanged(at: 1001);
        self::assertTrue($run->dirty);
        self::assertTrue($run->superseded);
        self::assertSame(RunStatus::NotStarted, $run->status);

        $this->expectException(InvalidTransition::class);
        $run->start();
    }

    public function testSupersedeIsIllegalOnTerminalRun(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->complete(at: 1002);

        $this->expectException(InvalidTransition::class);
        $run->supersede();
    }

    public function testContentChangeAfterCompletionOnlyMarksDirty(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->complete(at: 1002);

        $run->notifyContentChanged(at: 1003);

        self::assertTrue($run->dirty);
        self::assertFalse($run->superseded);
        self::assertSame(RunStatus::Completed, $run->status);
    }

    public function testMarkDirtyDoesNotSupersede(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->markDirty(at: 1001);

        self::assertTrue($run->dirty);
        self::assertFalse($run->superseded);
        self::assertSame(RunStatus::NotStarted, $run->status);
    }

    public function testExplicitStartFlagPersistsThroughSerialization(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        self::assertFalse($run->explicitStart, 'a fresh run is never explicit by default');
        $run->markDirty(at: 1001);
        $run->markExplicitStart(at: 1002);

        // The explicit-start marker survives a persist/rehydrate cycle so a
        // later WP-Cron tick still knows the user started this run (and may
        // begin it before a publish identity exists).
        self::assertTrue($run->explicitStart);
        $restored = ExportRun::fromArray($run->toArray());
        self::assertTrue($restored->explicitStart);
        self::assertSame($run->toArray(), $restored->toArray());
    }

    public function testResumePreservesAllMetadataThroughSerialization(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->advanceStage(RunStage::Exporting, at: 1002);
        $run->advanceStage(RunStage::Uploading, at: 1003);
        $run->recordProgress(40, at: 1004);
        $run->recordUploadIdentifier('tus-uuid-9', at: 1005);
        $run->recordUploadOffset(5242880, at: 1006);
        $run->recordResumeCursor('post_id:77', at: 1007);
        $run->recordRetry(at: 1008);
        $run->recordWarning('A redirect was rewritten.', at: 1009);
        $run->recordPublishIdentifiers('bafykcid456', 'website-2', 'ipns-k2', at: 1010);
        $run->pause(at: 1011);

        $restored = ExportRun::fromArray($run->toArray());
        self::assertSame(RunStatus::Paused, $restored->status);
        self::assertSame(RunStage::Uploading, $restored->stage);
        self::assertSame('run-1', $restored->runId);
        self::assertSame(40, $restored->progressCount);
        self::assertSame('tus-uuid-9', $restored->lastUploadIdentifier);
        self::assertSame(5242880, $restored->lastUploadOffsetBytes);
        self::assertSame('post_id:77', $restored->resumeCursor);
        self::assertSame(1, $restored->retryCount);
        self::assertSame(1, $restored->warningCount);
        self::assertSame('bafykcid456', $restored->publishCid);
        self::assertSame('website-2', $restored->websiteId);
        self::assertSame('ipns-k2', $restored->ipnsKey);
        self::assertSame($this->settings()->toArray(), $restored->settingsSnapshot());
        // round-trip is stable: persisting the restored run changes nothing.
        self::assertSame($run->toArray(), $restored->toArray());

        $restored->resume(at: 1012);
        self::assertSame(RunStatus::Running, $restored->status);
        self::assertSame(RunStage::Uploading, $restored->stage);
        self::assertSame('tus-uuid-9', $restored->lastUploadIdentifier);
        self::assertSame(5242880, $restored->lastUploadOffsetBytes);
        self::assertSame('bafykcid456', $restored->publishCid);
        self::assertSame($this->settings()->toArray(), $restored->settingsSnapshot());
    }

    public function testRecordsProbeAndSetupRuntimeStateLosslessly(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->recordProbe(new ProbeResult(
            Origin::fromParts('https', 'blog.example.test', null),
            'https://blog.example.test/',
            12288,
            37,
        ), at: 1002);
        $run->recordSetup(new SetupResult('/tmp/uploads/cast-work/run-1'), at: 1003);

        $restored = ExportRun::fromArray($run->toArray());

        self::assertNotNull($restored->probe);
        self::assertSame('https', $restored->probe->origin->scheme());
        self::assertSame('blog.example.test', $restored->probe->origin->host());
        self::assertNull($restored->probe->origin->port());
        self::assertSame('https://blog.example.test/', $restored->probe->finalUrl);
        self::assertSame(12288, $restored->probe->bytes);
        self::assertSame(37, $restored->probe->durationMs);
        self::assertNotNull($restored->setup);
        self::assertSame('/tmp/uploads/cast-work/run-1', $restored->setup->workDir);
        // round-trip is stable: persisting the restored run changes nothing.
        self::assertSame($run->toArray(), $restored->toArray());
    }

    public function testFreshRunRoundTripsNullRuntimeState(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);

        $restored = ExportRun::fromArray($run->toArray());

        self::assertNull($restored->probe);
        self::assertNull($restored->setup);
        self::assertNull($restored->discover);
        self::assertSame($run->toArray(), $restored->toArray());
    }

    public function testRecordsDiscoverRuntimeStateLosslessly(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->recordDiscover(new DiscoverResult(42), at: 1002);

        $restored = ExportRun::fromArray($run->toArray());

        self::assertNotNull($restored->discover);
        self::assertSame(42, $restored->discover->enqueued);
        // round-trip is stable: persisting the restored run changes nothing.
        self::assertSame($run->toArray(), $restored->toArray());
    }

    public function testRecordsDiscoverRuntimeStateOnlyWhileNotFinished(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->complete(at: 1002);

        $this->expectException(InvalidTransition::class);
        $run->recordDiscover(new DiscoverResult(1));
    }

    public function testFromArrayRejectsMalformedDiscoverNestedData(): void
    {
        $data = ExportRun::create('run-1', $this->settings(), at: 1000)->toArray();
        $data['discover'] = ['enqueued' => 'nope'];

        $this->expectException(InvalidArgumentException::class);
        ExportRun::fromArray($data);
    }

    public function testToArrayContainsTheDiscoverRuntimeStateSlot(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);

        $data = $run->toArray();

        // The discover runtime-state slot exists from day one, null until
        // recorded.
        self::assertArrayHasKey('discover', $data);
        self::assertNull($data['discover']);
    }

    public function testRecordsCaptureRuntimeStateLosslessly(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->recordCapture(new CaptureSummary(40, 1, 1), at: 1002);

        $restored = ExportRun::fromArray($run->toArray());

        self::assertNotNull($restored->capture);
        self::assertSame(40, $restored->capture->done);
        self::assertSame(1, $restored->capture->failed);
        self::assertSame(1, $restored->capture->skipped);
        // round-trip is stable: persisting the restored run changes nothing.
        self::assertSame($run->toArray(), $restored->toArray());
    }

    public function testRecordsCaptureRuntimeStateOnlyWhileNotFinished(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->complete(at: 1002);

        $this->expectException(InvalidTransition::class);
        $run->recordCapture(new CaptureSummary(1, 0, 0));
    }

    public function testFromArrayRejectsMalformedCaptureNestedData(): void
    {
        $data = ExportRun::create('run-1', $this->settings(), at: 1000)->toArray();
        $data['capture'] = ['done' => 'nope', 'failed' => 0, 'skipped' => 0];

        $this->expectException(InvalidArgumentException::class);
        ExportRun::fromArray($data);
    }

    public function testToArrayContainsTheCaptureRuntimeStateSlot(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);

        $data = $run->toArray();

        // The capture runtime-state slot exists from day one, null until
        // recorded.
        self::assertArrayHasKey('capture', $data);
        self::assertNull($data['capture']);
    }

    public function testRecordsRewriteRuntimeStateLosslessly(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->recordRewrite(new RewriteSummary(7, 1, 3), at: 1002);

        $restored = ExportRun::fromArray($run->toArray());

        self::assertNotNull($restored->rewrite);
        self::assertSame(7, $restored->rewrite->rewritten);
        self::assertSame(1, $restored->rewrite->passedThrough);
        // The collected-asset count is part of the summary and round-trips too.
        self::assertSame(3, $restored->rewrite->assetsCollected);
        // round-trip is stable: persisting the restored run changes nothing.
        self::assertSame($run->toArray(), $restored->toArray());
    }

    public function testRecordsRewriteRuntimeStateOnlyWhileNotFinished(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->complete(at: 1002);

        $this->expectException(InvalidTransition::class);
        $run->recordRewrite(new RewriteSummary(1, 0));
    }

    public function testFromArrayRejectsMalformedRewriteNestedData(): void
    {
        $data = ExportRun::create('run-1', $this->settings(), at: 1000)->toArray();
        $data['rewrite'] = ['rewritten' => 'nope', 'passed_through' => 0];

        $this->expectException(InvalidArgumentException::class);
        ExportRun::fromArray($data);
    }

    public function testToArrayContainsTheRewriteRuntimeStateSlot(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);

        $data = $run->toArray();

        // The rewrite runtime-state slot exists from day one, null until
        // recorded.
        self::assertArrayHasKey('rewrite', $data);
        self::assertNull($data['rewrite']);
    }

    public function testRecordsPackRuntimeStateLosslessly(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->recordPack(new PackResult(
            PackStatus::Completed,
            42,
            0,
            12345,
            '/tmp/exports/run-1.zip',
            '/tmp/exports/manifest.json',
        ), at: 1002);

        $restored = ExportRun::fromArray($run->toArray());

        self::assertNotNull($restored->pack);
        self::assertSame(PackStatus::Completed, $restored->pack->status);
        self::assertSame(42, $restored->pack->filesAdded);
        self::assertSame(0, $restored->pack->filesSkipped);
        self::assertSame(12345, $restored->pack->bytesWritten);
        self::assertSame('/tmp/exports/run-1.zip', $restored->pack->zipPath);
        self::assertSame('/tmp/exports/manifest.json', $restored->pack->manifestPath);
        self::assertFalse($restored->pack->hasWarnings());
        // round-trip is stable: persisting the restored run changes nothing.
        self::assertSame($run->toArray(), $restored->toArray());
    }

    public function testRecordsPackRuntimeStateOnlyWhileNotFinished(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->complete(at: 1002);

        $this->expectException(InvalidTransition::class);
        $run->recordPack(new PackResult(
            PackStatus::Failed,
            0,
            0,
            0,
            '/tmp/exports/run-1.zip',
            null,
            ['pending_items'],
        ));
    }

    public function testFromArrayRejectsMalformedPackNestedData(): void
    {
        $data = ExportRun::create('run-1', $this->settings(), at: 1000)->toArray();
        $data['pack'] = ['status' => 'bogus'];

        $this->expectException(InvalidArgumentException::class);
        ExportRun::fromArray($data);
    }

    public function testToArrayContainsThePackRuntimeStateSlot(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);

        $data = $run->toArray();

        // The pack runtime-state slot exists from day one, null until
        // recorded.
        self::assertArrayHasKey('pack', $data);
        self::assertNull($data['pack']);
    }

    public function testRecordsWrapupRuntimeStateLosslessly(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->recordWrapup(WrapupResult::completed(true, ['pack warning']), at: 1002);

        $restored = ExportRun::fromArray($run->toArray());

        self::assertNotNull($restored->wrapup);
        self::assertTrue($restored->wrapup->success);
        self::assertTrue($restored->wrapup->workDirDeleted);
        self::assertFalse($restored->wrapup->hasIntegrityFailure());
        self::assertSame([], $restored->wrapup->integrityErrors);
        self::assertNull($restored->wrapup->deletionFailure);
        self::assertSame(['pack warning'], $restored->wrapup->warnings);
        // round-trip is stable: persisting the restored run changes nothing.
        self::assertSame($run->toArray(), $restored->toArray());
    }

    public function testRecordsWrapupRuntimeStateOnlyWhileNotFinished(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->complete(at: 1002);

        $this->expectException(InvalidTransition::class);
        $run->recordWrapup(WrapupResult::completed(true));
    }

    public function testFromArrayRejectsMalformedWrapupNestedData(): void
    {
        $data = ExportRun::create('run-1', $this->settings(), at: 1000)->toArray();
        $data['wrapup'] = ['success' => 'bogus'];

        $this->expectException(InvalidArgumentException::class);
        ExportRun::fromArray($data);
    }

    public function testToArrayContainsTheWrapupRuntimeStateSlot(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);

        $data = $run->toArray();

        // The wrapup runtime-state slot exists from day one, null until
        // recorded.
        self::assertArrayHasKey('wrapup', $data);
        self::assertNull($data['wrapup']);
    }

    public function testRecordsPublishRuntimeStateLosslessly(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->recordPublishBoundary(
            PublishBoundaryResult::completed('QmHash', 'website-1', 'k1-example.com'),
            at: 1002,
        );

        $restored = ExportRun::fromArray($run->toArray());

        self::assertNotNull($restored->publish);
        self::assertSame(PublishBoundaryStatus::Completed, $restored->publish->status);
        self::assertSame('QmHash', $restored->publish->cid);
        self::assertSame('website-1', $restored->publish->websiteId);
        self::assertSame('k1-example.com', $restored->publish->ipnsKey);
        self::assertNull($restored->publish->message);
        self::assertTrue($restored->publish->isSuccess());
        // round-trip is stable: persisting the restored run changes nothing.
        self::assertSame($run->toArray(), $restored->toArray());
    }

    public function testRecordsPublishRuntimeStateOnlyWhileNotFinished(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->complete(at: 1002);

        $this->expectException(InvalidTransition::class);
        $run->recordPublishBoundary(PublishBoundaryResult::failed('connection refused'));
    }

    public function testFromArrayRejectsMalformedPublishNestedData(): void
    {
        $data = ExportRun::create('run-1', $this->settings(), at: 1000)->toArray();
        $data['publish'] = ['status' => 'bogus'];

        $this->expectException(InvalidArgumentException::class);
        ExportRun::fromArray($data);
    }

    public function testToArrayContainsThePublishRuntimeStateSlot(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);

        $data = $run->toArray();

        // The publish runtime-state slot exists from day one, null until
        // recorded.
        self::assertArrayHasKey('publish', $data);
        self::assertNull($data['publish']);
    }

    public function testRecordsRuntimeStateOnlyWhileNotFinished(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);
        $run->complete(at: 1002);

        $this->expectException(InvalidTransition::class);
        $run->recordProbe(new ProbeResult(
            Origin::fromParts('https', 'blog.example.test', null),
            'https://blog.example.test/',
            1024,
            1,
        ));
    }

    public function testFromArrayRejectsMalformedRuntimeStateNestedData(): void
    {
        $data = ExportRun::create('run-1', $this->settings(), at: 1000)->toArray();
        $data['probe'] = ['origin_scheme' => 9];

        $this->expectException(InvalidArgumentException::class);
        ExportRun::fromArray($data);
    }

    public function testSchemaVersionRemainsOne(): void
    {
        // Pre-release: no persisted run data has ever been released, so the
        // serialized format stays at version 1.
        self::assertSame(1, ExportRun::SCHEMA_VERSION);
    }

    #[DataProvider('schemaVersionToleranceProvider')]
    public function testFromArrayIgnoresMissingOrDifferingSchemaVersion(mixed $schemaVersion): void
    {
        $data = ExportRun::create('run-1', $this->settings(), at: 1000)->toArray();
        if ($schemaVersion === null) {
            unset($data['schema_version']);
        } else {
            $data['schema_version'] = $schemaVersion;
        }

        $restored = ExportRun::fromArray($data);

        self::assertSame('run-1', $restored->runId);
        // Serialization normalises the emitted version back to the current one.
        self::assertSame(1, $restored->toArray()['schema_version']);
        self::assertSame($this->settings()->toArray(), $restored->settingsSnapshot());
        self::assertNull($restored->probe);
        self::assertNull($restored->setup);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function schemaVersionToleranceProvider(): iterable
    {
        yield 'missing schema version' => [null];
        yield 'pre-release version 1' => [1];
        yield 'differing version' => [999];
    }

    #[DataProvider('invalidPersistedDataProvider')]
    public function testFromArrayRejectsMissingOrUnknownData(mixed $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExportRun::fromArray($data);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidPersistedDataProvider(): iterable
    {
        yield 'null' => [null];
        yield 'non-array' => ['run'];
        yield 'missing run_id' => [['schema_version' => ExportRun::SCHEMA_VERSION]];
        yield 'missing settings' => [['schema_version' => ExportRun::SCHEMA_VERSION, 'run_id' => 'r']];
    }

    public function testToArrayContainsSchemaVersionAndSnakeCaseFields(): void
    {
        $run = ExportRun::create('run-1', $this->settings(), at: 1000);
        $run->start(at: 1001);

        $data = $run->toArray();

        self::assertSame(ExportRun::SCHEMA_VERSION, $data['schema_version']);
        self::assertSame('run-1', $data['run_id']);
        self::assertSame('running', $data['status']);
        self::assertSame('idle', $data['stage']);

        // The runtime-state slots exist from day one, null until recorded.
        self::assertArrayHasKey('probe', $data);
        self::assertArrayHasKey('setup', $data);
        self::assertNull($data['probe']);
        self::assertNull($data['setup']);

        /** @var array<string, mixed> $settings */
        $settings = $data['settings'];
        self::assertSame('blog.example.test', $settings['hostname']);
    }

    /**
     * Actions that a superseded active run must refuse.
     *
     * @return array<string, Closure(ExportRun): void>
     */
    private function blockedAfterSupersede(): array
    {
        return [
            'complete' => static function (ExportRun $run): void {
                $run->complete();
            },
            'completeWithWarnings after warning' => static function (ExportRun $run): void {
                $run->recordWarning('w');
                $run->completeWithWarnings();
            },
            'pause' => static function (ExportRun $run): void {
                $run->pause();
            },
            'advance stage' => static function (ExportRun $run): void {
                $run->advanceStage(RunStage::Uploading);
            },
        ];
    }

    private function restore(ExportRun $run): ExportRun
    {
        return ExportRun::fromArray($run->toArray());
    }
}
