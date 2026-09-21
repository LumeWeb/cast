<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\CaptureSummary;
use LumeWeb\Cast\Export\DiscoverResult;
use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\InMemoryRunRepository;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\PackResult;
use LumeWeb\Cast\Export\PackStatus;
use LumeWeb\Cast\Export\PipelineContext;
use LumeWeb\Cast\Export\PipelineStage;
use LumeWeb\Cast\Export\PipelineStageKey;
use LumeWeb\Cast\Export\PipelineState;
use LumeWeb\Cast\Export\ProbeResult;
use LumeWeb\Cast\Export\PublishBoundaryResult;
use LumeWeb\Cast\Export\PublishBoundaryStatus;
use LumeWeb\Cast\Export\RewriteSummary;
use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Export\RunStage;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Export\SetupResult;
use LumeWeb\Cast\Export\StageResult;
use LumeWeb\Cast\Export\UnwiredStage;
use LumeWeb\Cast\Export\WrapupResult;
use LumeWeb\Cast\Jobs\ExportPipelineTick;
use LumeWeb\Cast\Jobs\ExportTickRunner;
use LumeWeb\Cast\Jobs\FixedClock;
use LumeWeb\Cast\Jobs\InMemoryIdentityGateway;
use LumeWeb\Cast\Jobs\InMemoryLock;
use LumeWeb\Cast\Jobs\TickConfig;
use LumeWeb\Cast\Jobs\TickOutcomeKind;
use PHPUnit\Framework\TestCase;

/**
 * The real export orchestrator: exactly one bounded pipeline stage unit per
 * tick, driven purely through the injected {@see RunRepository}, resuming at
 * the saved stage/cursor, and reaching a terminal status only after pack and
 * wrapup have both completed.
 */
final class ExportPipelineTickTest extends TestCase
{
    private InMemoryRunRepository $repository;

    /**
     * A completed publish boundary result used across the publish hydrate/
     * mirror tests.
     */
    private static function publishBoundaryResult(): PublishBoundaryResult
    {
        return PublishBoundaryResult::completed('QmHash', 'website-1', 'k1-example.com');
    }

    protected function setUp(): void
    {
        $this->repository = new InMemoryRunRepository();
    }

    /**
     * Build the orchestrator over the given stage overrides; every stage the
     * caller does not override is an {@see UnwiredStage} so an accidental call
     * fails loudly instead of silently spinning.
     *
     * @param array<string, PipelineStage> $overrides
     * @param PipelineState|null           $state    the shared PipelineState the
     *                                               orchestrator hydrates/mirrors;
     *                                               defaults to a fresh one
     */
    private function pipeline(array $overrides = [], ?PipelineState $state = null): ExportPipelineTick
    {
        $stages = [];
        foreach (PipelineStageKey::cases() as $key) {
            $stages[$key->value] = $overrides[$key->value] ?? new UnwiredStage($key);
        }

        return new ExportPipelineTick(new PipelineContext(runs: $this->repository, stages: $stages, state: $state));
    }

    private function startedRun(string $id, int $at = 1000): ExportRun
    {
        $run = ExportRun::create($id, new RunSettings(hostname: 'blog.example.test'), at: $at);
        $run->start(at: $at);
        $this->repository->save($run);

        return $run;
    }

    private function stageFinishingInOneUnit(PipelineStageKey $key): FakePipelineStage
    {
        $stage = new FakePipelineStage($key);
        $stage->respond = static fn (): StageResult => StageResult::done('');

        return $stage;
    }

    /**
     * @return array<string, FakePipelineStage>
     */
    private function allStagesFinishingInOneUnit(): array
    {
        $stages = [];
        foreach (PipelineStageKey::cases() as $key) {
            $stages[$key->value] = $this->stageFinishingInOneUnit($key);
        }

        return $stages;
    }

    public function testRunsEveryStageInOrderExactlyOneUnitPerTickThenCompletes(): void
    {
        $stages = $this->allStagesFinishingInOneUnit();
        $tick = $this->pipeline($stages);
        $run = $this->startedRun('run-1');

        $results = [];
        for ($t = 0; $t < count(PipelineStageKey::cases()); ++$t) {
            $results[] = $tick->perform($run, 1000 + $t);
        }

        // Each stage executed exactly once, in pipeline order.
        $callOrder = [];
        foreach (PipelineStageKey::cases() as $key) {
            foreach ($stages[$key->value]->calls as $call) {
                $callOrder[] = $call['key'];
            }
        }
        self::assertSame(PipelineStageKey::cases(), $callOrder);
        foreach (PipelineStageKey::cases() as $key) {
            self::assertCount(1, $stages[$key->value]->calls);
        }

        // All but the final tick keep working; the last stage's done terminal.
        $last = count($results) - 1;
        foreach (array_slice($results, 0, $last) as $result) {
            self::assertFalse($result->finished);
            self::assertNull($result->failure);
            self::assertFalse($result->stale);
        }
        self::assertTrue($results[$last]->finished);

        self::assertSame(RunStatus::Completed, $run->status);
        self::assertSame(RunStage::Finished, $run->stage);
        self::assertTrue($run->isTerminal());
    }

    public function testPersistedStateAdvancesThroughTheRepository(): void
    {
        $stages = $this->allStagesFinishingInOneUnit();
        $tick = $this->pipeline($stages);
        $run = $this->startedRun('run-1');

        $tick->perform($run, 1001);

        $saved = $this->repository->find('run-1');
        self::assertNotNull($saved);
        self::assertSame('setup|', $saved->resumeCursor);
        self::assertSame(RunStage::Exporting, $saved->stage);
        self::assertSame(RunStatus::Running, $saved->status);
    }

    public function testResumesAtTheSavedStageAndCursor(): void
    {
        $capture = new FakePipelineStage(PipelineStageKey::Capture);
        $capture->respond = static fn (string $cursor): StageResult => StageResult::more($cursor, progress: 1);
        $tick = $this->pipeline([PipelineStageKey::Capture->value => $capture]);

        $run = $this->startedRun('run-1');
        $run->advanceStage(RunStage::Exporting, at: 1001);
        $run->recordResumeCursor('capture|post:7:50', at: 1001);
        $this->repository->save($run);

        $result = $tick->perform($run, 1002);

        self::assertFalse($result->finished);
        self::assertCount(1, $capture->calls);
        self::assertSame('post:7:50', $capture->calls[0]['cursor']);

        $saved = $this->repository->find('run-1');
        self::assertNotNull($saved);
        self::assertSame('capture|post:7:50', $saved->resumeCursor);
        self::assertSame(1, $saved->progressCount);
    }

    public function testAStageMayNeedSeveralBoundedUnitsBeforeItFinishes(): void
    {
        $capture = new FakePipelineStage(PipelineStageKey::Capture);
        $capture->sequence = [
            StageResult::more('post:8:50'),
            StageResult::done('post:8:50'),
        ];
        $tick = $this->pipeline([PipelineStageKey::Capture->value => $capture]);

        $run = $this->startedRun('run-1');
        $run->advanceStage(RunStage::Exporting, at: 1001);
        $run->recordResumeCursor('capture|post:7:50', at: 1001);
        $this->repository->save($run);

        $first = $tick->perform($run, 1002);
        self::assertFalse($first->finished);
        self::assertSame('capture|post:8:50', $this->repository->find('run-1')?->resumeCursor);

        $second = $tick->perform($run, 1003);
        self::assertFalse($second->finished);
        self::assertSame('rewrite|', $this->repository->find('run-1')?->resumeCursor);

        self::assertSame(['post:7:50', 'post:8:50'], array_column($capture->calls, 'cursor'));
    }

    public function testAdvancesTheCoarseStageOnlyAtGroupBoundaries(): void
    {
        $stages = $this->allStagesFinishingInOneUnit();
        $tick = $this->pipeline($stages);
        $run = $this->startedRun('run-1');

        // probe → setup → discover → capture → rewrite all live in Exporting.
        for ($t = 0; $t < 4; ++$t) {
            $tick->perform($run, 1000 + $t);
            self::assertSame(RunStage::Exporting, $run->stage);
        }

        // rewrite finishes → next boundary is pack → Uploading.
        $tick->perform($run, 1004);
        self::assertSame(RunStage::Uploading, $run->stage);

        // pack finishes → next boundary is wrapup → Publishing.
        $tick->perform($run, 1005);
        self::assertSame(RunStage::Publishing, $run->stage);
    }

    public function testTransitionsTerminalOnlyAfterPackWrapupAndPublishAreDone(): void
    {
        $publish = new FakePipelineStage(PipelineStageKey::Publish);
        $publish->respond = static fn (): StageResult => StageResult::more('publishing');
        $stages = $this->allStagesFinishingInOneUnit();
        $stages[PipelineStageKey::Publish->value] = $publish;
        $tick = $this->pipeline($stages);
        $run = $this->startedRun('run-1');

        // Run every stage once: publish returns more, so the run must not yet
        // be terminal even though pack AND wrapup have both completed.
        for ($t = 0; $t < count(PipelineStageKey::cases()); ++$t) {
            $result = $tick->perform($run, 1000 + $t);
            self::assertFalse($result->finished);
        }
        self::assertSame(RunStatus::Running, $run->status);
        self::assertFalse($run->isTerminal());
        self::assertSame('publish|publishing', $this->repository->find('run-1')?->resumeCursor);

        // The follow-up publish unit finally finishes → only then is the run
        // completed.
        $publish->respond = static fn (): StageResult => StageResult::done('publishing');
        $final = $tick->perform($run, 1008);

        self::assertTrue($final->finished);
        self::assertSame(RunStatus::Completed, $run->status);
        self::assertTrue($run->isTerminal());
    }

    public function testCompletesWithWarningsWhenWarningsWereRecorded(): void
    {
        $capture = new FakePipelineStage(PipelineStageKey::Capture);
        $capture->respond = static fn (): StageResult => StageResult::done('', warnings: ['skipped broken image']);
        $stages = $this->allStagesFinishingInOneUnit();
        $stages[PipelineStageKey::Capture->value] = $capture;
        $tick = $this->pipeline($stages);
        $run = $this->startedRun('run-1');

        $results = [];
        for ($t = 0; $t < count(PipelineStageKey::cases()); ++$t) {
            $results[] = $tick->perform($run, 1000 + $t);
        }

        $last = count($results) - 1;
        self::assertTrue($results[$last]->finished);
        self::assertSame(RunStatus::CompletedWithWarnings, $run->status);
        self::assertSame(1, $run->warningCount);
        self::assertTrue($run->isTerminal());
    }

    public function testAccumulatesProgressAndWarningsAcrossTicks(): void
    {
        $capture = new FakePipelineStage(PipelineStageKey::Capture);
        $capture->respond = static fn (): StageResult => StageResult::more('post:8:50', progress: 5, warnings: ['slow']);
        $tick = $this->pipeline([PipelineStageKey::Capture->value => $capture]);

        $run = $this->startedRun('run-1');
        $run->advanceStage(RunStage::Exporting, at: 1001);
        $run->recordResumeCursor('capture|post:7:50', at: 1001);
        $this->repository->save($run);

        $tick->perform($run, 1002);
        $tick->perform($run, 1003);

        $saved = $this->repository->find('run-1');
        self::assertNotNull($saved);
        self::assertSame(10, $saved->progressCount);
        self::assertSame(2, $saved->warningCount);
    }

    public function testPausedRunNeverAdvances(): void
    {
        $probe = new FakePipelineStage(PipelineStageKey::Probe);
        $tick = $this->pipeline([PipelineStageKey::Probe->value => $probe]);

        $run = $this->startedRun('run-1');
        $run->pause(at: 1002);
        $this->repository->save($run);

        $result = $tick->perform($run, 1003);

        self::assertFalse($result->finished);
        self::assertCount(0, $probe->calls);
        self::assertSame(RunStatus::Paused, $run->status);
    }

    public function testTerminalRunNeverAdvances(): void
    {
        $probe = new FakePipelineStage(PipelineStageKey::Probe);
        $tick = $this->pipeline([PipelineStageKey::Probe->value => $probe]);

        $run = $this->startedRun('run-1');
        $run->complete(at: 1002);
        $this->repository->save($run);

        $result = $tick->perform($run, 1003);

        self::assertFalse($result->finished);
        self::assertCount(0, $probe->calls);
    }

    public function testSupersededLiveRunIsCancelledWithoutAdvancing(): void
    {
        $probe = new FakePipelineStage(PipelineStageKey::Probe);
        $tick = $this->pipeline([PipelineStageKey::Probe->value => $probe]);

        $run = $this->startedRun('run-1');
        $run->supersede(at: 1002);
        $this->repository->save($run);

        $result = $tick->perform($run, 1003);

        self::assertFalse($result->finished);
        self::assertCount(0, $probe->calls);
        self::assertSame(RunStatus::Cancelled, $run->status);
        self::assertSame(RunStatus::Cancelled, $this->repository->find('run-1')?->status);
        self::assertTrue($run->isTerminal());
    }

    public function testStageFailureReturnsASafeReasonAndPersistsState(): void
    {
        $capture = new FakePipelineStage(PipelineStageKey::Capture);
        $capture->respond = static fn (): StageResult => StageResult::fail('capture timed out', warnings: ['retrying']);
        $tick = $this->pipeline([PipelineStageKey::Capture->value => $capture]);

        $run = $this->startedRun('run-1');
        $run->advanceStage(RunStage::Exporting, at: 1001);
        $run->recordResumeCursor('capture|post:7:50', at: 1001);
        $this->repository->save($run);

        $result = $tick->perform($run, 1002);

        self::assertFalse($result->finished);
        self::assertSame('capture timed out', $result->failure);
        self::assertCount(1, $capture->calls);
        self::assertSame(RunStatus::Running, $run->status);
        self::assertFalse($run->isTerminal());

        // The cursor is untouched so a retry re-runs the same unit, and the
        // warning was persisted before the failure surfaced.
        $saved = $this->repository->find('run-1');
        self::assertNotNull($saved);
        self::assertSame('capture|post:7:50', $saved->resumeCursor);
        self::assertSame(1, $saved->warningCount);
    }

    public function testStageCancellationMarksTheRunCancelledAndPersists(): void
    {
        $capture = new FakePipelineStage(PipelineStageKey::Capture);
        $capture->respond = static fn (): StageResult => StageResult::cancel('post:9:50');
        $tick = $this->pipeline([PipelineStageKey::Capture->value => $capture]);

        $run = $this->startedRun('run-1');
        $run->advanceStage(RunStage::Exporting, at: 1001);
        $run->recordResumeCursor('capture|post:7:50', at: 1001);
        $this->repository->save($run);

        $result = $tick->perform($run, 1002);

        self::assertFalse($result->finished);
        self::assertNull($result->failure);
        self::assertCount(1, $capture->calls);
        self::assertSame(RunStatus::Cancelled, $run->status);
        self::assertSame(RunStatus::Cancelled, $this->repository->find('run-1')?->status);
        self::assertTrue($run->isTerminal());
    }

    public function testUnwiredPipelineFailsInsteadOfReturningMoreForever(): void
    {
        $tick = $this->pipeline();
        $run = $this->startedRun('run-1');

        $result = $tick->perform($run, 1001);

        self::assertFalse($result->finished);
        self::assertNotNull($result->failure);
        self::assertStringContainsString('probe', $result->failure);
        self::assertStringContainsString('not wired', $result->failure);
    }

    public function testUnwiredPipelineFailsTheRunThroughTheRunner(): void
    {
        $pipelineTick = $this->pipeline();
        $clock = new FixedClock(1000);
        $runner = new ExportTickRunner(
            clock: $clock,
            lock: new InMemoryLock($clock),
            repository: $this->repository,
            tick: $pipelineTick,
            identity: new InMemoryIdentityGateway(true),
            config: new TickConfig(),
        );

        $run = ExportRun::create('run-1', new RunSettings(hostname: 'blog.example.test', maxRetries: 0), at: 1000);
        $run->markDirty(at: 1000);
        $this->repository->save($run);

        $outcome = $runner->tick(at: 1001);

        self::assertSame(TickOutcomeKind::Failed, $outcome->kind);
        $latest = $this->repository->latest();
        self::assertNotNull($latest);
        self::assertSame(RunStatus::Failed, $latest->status);
        self::assertTrue($latest->isTerminal());
        self::assertStringContainsString('not wired', (string) $latest->lastError);
    }

    public function testFreshPipelineStateSeesPersistedProbeAndSetupBeforeTheUnitRuns(): void
    {
        // First request: the probe unit records Probe + Setup into the shared
        // state; the orchestrator mirrors them onto the run and persists them.
        $firstState = new PipelineState();
        $writer = new RuntimeStateFakeStage(PipelineStageKey::Probe, $firstState);
        $writer->writesProbe = new ProbeResult(
            Origin::fromParts('https', 'Blog.Example.test', 8443),
            'https://blog.example.test/',
            4096,
            42,
        );
        $writer->writesSetup = new SetupResult('/tmp/uploads/cast-work/run-1');
        $tickA = $this->pipeline([PipelineStageKey::Probe->value => $writer], $firstState);
        $run = $this->startedRun('run-1');
        $tickA->perform($run, 1001);

        $saved = $this->repository->find('run-1');
        self::assertNotNull($saved);
        self::assertNotNull($saved->probe);
        self::assertNotNull($saved->setup);

        // Second request: a brand-new shared PipelineState and a brand-new
        // orchestrator over the persisted run. The setup unit must observe the
        // persisted Probe/Setup values re-hydrated before it executes — proof
        // that a fresh WP-Cron request resumes instead of re-running probe.
        $secondState = new PipelineState();
        $observer = new RuntimeStateFakeStage(PipelineStageKey::Setup, $secondState);
        $tickB = $this->pipeline([PipelineStageKey::Setup->value => $observer], $secondState);
        $freshRun = $this->repository->find('run-1');
        self::assertNotNull($freshRun);

        $tickB->perform($freshRun, 1002);

        self::assertNotNull($observer->seenProbe);
        self::assertSame('https', $observer->seenProbe->origin->scheme());
        self::assertSame('Blog.Example.test', $observer->seenProbe->origin->host());
        self::assertSame(8443, $observer->seenProbe->origin->port());
        self::assertSame('https://blog.example.test/', $observer->seenProbe->finalUrl);
        self::assertSame(4096, $observer->seenProbe->bytes);
        self::assertSame(42, $observer->seenProbe->durationMs);
        self::assertNotNull($observer->seenSetup);
        self::assertSame('/tmp/uploads/cast-work/run-1', $observer->seenSetup->workDir);
    }

    public function testMirrorsProbeAndSetupResultsBackToTheRunAndPersistsThem(): void
    {
        $state = new PipelineState();
        $writer = new RuntimeStateFakeStage(PipelineStageKey::Probe, $state);
        $writer->writesProbe = new ProbeResult(
            Origin::fromParts('https', 'blog.example.test', null),
            'https://blog.example.test/',
            2048,
            12,
        );
        $writer->writesSetup = new SetupResult('/work/run-1');
        $tick = $this->pipeline([PipelineStageKey::Probe->value => $writer], $state);
        $run = $this->startedRun('run-1');

        $tick->perform($run, 1001);

        // Mirrored onto the live aggregate...
        self::assertNotNull($run->probe);
        self::assertSame('https', $run->probe->origin->scheme());
        self::assertSame('blog.example.test', $run->probe->origin->host());
        self::assertNull($run->probe->origin->port());
        self::assertSame('https://blog.example.test/', $run->probe->finalUrl);
        self::assertSame(2048, $run->probe->bytes);
        self::assertSame(12, $run->probe->durationMs);
        self::assertNotNull($run->setup);
        self::assertSame('/work/run-1', $run->setup->workDir);

        // ...and persisted through the repository, round-tripping losslessly.
        $saved = $this->repository->find('run-1');
        self::assertNotNull($saved);
        self::assertNotNull($saved->probe);
        self::assertSame('https', $saved->probe->origin->scheme());
        self::assertSame('blog.example.test', $saved->probe->origin->host());
        self::assertNull($saved->probe->origin->port());
        self::assertNotNull($saved->setup);
        self::assertSame('/work/run-1', $saved->setup->workDir);
        self::assertSame($saved->toArray(), ExportRun::fromArray($saved->toArray())->toArray());
    }

    public function testFreshPipelineStateSeesPersistedDiscoverBeforeTheUnitRuns(): void
    {
        // First request: the probe unit records Probe + Setup + Discover into
        // the shared state; the orchestrator mirrors them onto the run and
        // persists them.
        $firstState = new PipelineState();
        $writer = new RuntimeStateFakeStage(PipelineStageKey::Probe, $firstState);
        $writer->writesProbe = new ProbeResult(
            Origin::fromParts('https', 'Blog.Example.test', 8443),
            'https://blog.example.test/',
            4096,
            42,
        );
        $writer->writesSetup = new SetupResult('/tmp/uploads/cast-work/run-1');
        $writer->writesDiscover = new DiscoverResult(12);
        $tickA = $this->pipeline([PipelineStageKey::Probe->value => $writer], $firstState);
        $run = $this->startedRun('run-1');
        $tickA->perform($run, 1001);

        $saved = $this->repository->find('run-1');
        self::assertNotNull($saved);
        self::assertNotNull($saved->probe);
        self::assertNotNull($saved->setup);
        self::assertNotNull($saved->discover);
        self::assertSame(12, $saved->discover->enqueued);

        // Second request: a brand-new shared PipelineState and a brand-new
        // orchestrator over the persisted run. The setup unit must observe the
        // persisted Discover value re-hydrated before it executes — proof that
        // a fresh WP-Cron request resumes instead of re-running discovery.
        $secondState = new PipelineState();
        $observer = new RuntimeStateFakeStage(PipelineStageKey::Setup, $secondState);
        $tickB = $this->pipeline([PipelineStageKey::Setup->value => $observer], $secondState);
        $freshRun = $this->repository->find('run-1');
        self::assertNotNull($freshRun);

        $tickB->perform($freshRun, 1002);

        self::assertNotNull($observer->seenProbe);
        self::assertSame('https', $observer->seenProbe->origin->scheme());
        self::assertSame('Blog.Example.test', $observer->seenProbe->origin->host());
        self::assertSame(8443, $observer->seenProbe->origin->port());
        self::assertSame('https://blog.example.test/', $observer->seenProbe->finalUrl);
        self::assertNotNull($observer->seenSetup);
        self::assertSame('/tmp/uploads/cast-work/run-1', $observer->seenSetup->workDir);
        self::assertNotNull($observer->seenDiscover);
        self::assertSame(12, $observer->seenDiscover->enqueued);
    }

    public function testMirrorsDiscoverResultBackToTheRunAndPersistsThem(): void
    {
        $state = new PipelineState();
        $writer = new RuntimeStateFakeStage(PipelineStageKey::Probe, $state);
        $writer->writesProbe = new ProbeResult(
            Origin::fromParts('https', 'blog.example.test', null),
            'https://blog.example.test/',
            2048,
            12,
        );
        $writer->writesSetup = new SetupResult('/work/run-1');
        $writer->writesDiscover = new DiscoverResult(7);
        $tick = $this->pipeline([PipelineStageKey::Probe->value => $writer], $state);
        $run = $this->startedRun('run-1');

        $tick->perform($run, 1001);

        // Mirrored onto the live aggregate...
        self::assertNotNull($run->probe);
        self::assertSame('https', $run->probe->origin->scheme());
        self::assertSame('blog.example.test', $run->probe->origin->host());
        self::assertNull($run->probe->origin->port());
        self::assertNotNull($run->setup);
        self::assertSame('/work/run-1', $run->setup->workDir);
        self::assertNotNull($run->discover);
        self::assertSame(7, $run->discover->enqueued);

        // ...and persisted through the repository, round-tripping losslessly.
        $saved = $this->repository->find('run-1');
        self::assertNotNull($saved);
        self::assertNotNull($saved->probe);
        self::assertSame('https', $saved->probe->origin->scheme());
        self::assertSame('blog.example.test', $saved->probe->origin->host());
        self::assertNull($saved->probe->origin->port());
        self::assertNotNull($saved->setup);
        self::assertSame('/work/run-1', $saved->setup->workDir);
        self::assertNotNull($saved->discover);
        self::assertSame(7, $saved->discover->enqueued);
        self::assertSame($saved->toArray(), ExportRun::fromArray($saved->toArray())->toArray());
    }

    public function testFreshPipelineStateSeesPersistedCaptureBeforeTheUnitRuns(): void
    {
        // First request: the probe unit records Probe + Setup + Discover +
        // Capture into the shared state; the orchestrator mirrors them onto the
        // run and persists them.
        $firstState = new PipelineState();
        $writer = new RuntimeStateFakeStage(PipelineStageKey::Probe, $firstState);
        $writer->writesProbe = new ProbeResult(
            Origin::fromParts('https', 'Blog.Example.test', 8443),
            'https://blog.example.test/',
            4096,
            42,
        );
        $writer->writesSetup = new SetupResult('/tmp/uploads/cast-work/run-1');
        $writer->writesDiscover = new DiscoverResult(12);
        $writer->writesCapture = new CaptureSummary(10, 1, 1);
        $tickA = $this->pipeline([PipelineStageKey::Probe->value => $writer], $firstState);
        $run = $this->startedRun('run-1');
        $tickA->perform($run, 1001);

        $saved = $this->repository->find('run-1');
        self::assertNotNull($saved);
        self::assertNotNull($saved->probe);
        self::assertNotNull($saved->setup);
        self::assertNotNull($saved->discover);
        self::assertSame(12, $saved->discover->enqueued);
        self::assertNotNull($saved->capture);
        self::assertSame(10, $saved->capture->done);
        self::assertSame(1, $saved->capture->failed);
        self::assertSame(1, $saved->capture->skipped);

        // Second request: a brand-new shared PipelineState and a brand-new
        // orchestrator over the persisted run. The setup unit must observe the
        // persisted Capture value re-hydrated before it executes — proof that
        // a fresh WP-Cron request resumes instead of re-running capture.
        $secondState = new PipelineState();
        $observer = new RuntimeStateFakeStage(PipelineStageKey::Setup, $secondState);
        $tickB = $this->pipeline([PipelineStageKey::Setup->value => $observer], $secondState);
        $freshRun = $this->repository->find('run-1');
        self::assertNotNull($freshRun);

        $tickB->perform($freshRun, 1002);

        self::assertNotNull($observer->seenProbe);
        self::assertSame('https', $observer->seenProbe->origin->scheme());
        self::assertSame('Blog.Example.test', $observer->seenProbe->origin->host());
        self::assertSame(8443, $observer->seenProbe->origin->port());
        self::assertSame('https://blog.example.test/', $observer->seenProbe->finalUrl);
        self::assertNotNull($observer->seenSetup);
        self::assertSame('/tmp/uploads/cast-work/run-1', $observer->seenSetup->workDir);
        self::assertNotNull($observer->seenDiscover);
        self::assertSame(12, $observer->seenDiscover->enqueued);
        self::assertNotNull($observer->seenCapture);
        self::assertSame(10, $observer->seenCapture->done);
        self::assertSame(1, $observer->seenCapture->failed);
        self::assertSame(1, $observer->seenCapture->skipped);
    }

    public function testMirrorsCaptureResultBackToTheRunAndPersistsThem(): void
    {
        $state = new PipelineState();
        $writer = new RuntimeStateFakeStage(PipelineStageKey::Probe, $state);
        $writer->writesProbe = new ProbeResult(
            Origin::fromParts('https', 'blog.example.test', null),
            'https://blog.example.test/',
            2048,
            12,
        );
        $writer->writesSetup = new SetupResult('/work/run-1');
        $writer->writesDiscover = new DiscoverResult(7);
        $writer->writesCapture = new CaptureSummary(4, 2, 1);
        $tick = $this->pipeline([PipelineStageKey::Probe->value => $writer], $state);
        $run = $this->startedRun('run-1');

        $tick->perform($run, 1001);

        // Mirrored onto the live aggregate...
        self::assertNotNull($run->probe);
        self::assertSame('https', $run->probe->origin->scheme());
        self::assertSame('blog.example.test', $run->probe->origin->host());
        self::assertNull($run->probe->origin->port());
        self::assertNotNull($run->setup);
        self::assertSame('/work/run-1', $run->setup->workDir);
        self::assertNotNull($run->discover);
        self::assertSame(7, $run->discover->enqueued);
        self::assertNotNull($run->capture);
        self::assertSame(4, $run->capture->done);
        self::assertSame(2, $run->capture->failed);
        self::assertSame(1, $run->capture->skipped);

        // ...and persisted through the repository, round-tripping losslessly.
        $saved = $this->repository->find('run-1');
        self::assertNotNull($saved);
        self::assertNotNull($saved->probe);
        self::assertSame('https', $saved->probe->origin->scheme());
        self::assertSame('blog.example.test', $saved->probe->origin->host());
        self::assertNull($saved->probe->origin->port());
        self::assertNotNull($saved->setup);
        self::assertSame('/work/run-1', $saved->setup->workDir);
        self::assertNotNull($saved->discover);
        self::assertSame(7, $saved->discover->enqueued);
        self::assertNotNull($saved->capture);
        self::assertSame(4, $saved->capture->done);
        self::assertSame(2, $saved->capture->failed);
        self::assertSame(1, $saved->capture->skipped);
        self::assertSame($saved->toArray(), ExportRun::fromArray($saved->toArray())->toArray());
    }

    public function testFreshPipelineStateSeesPersistedRewriteBeforeTheUnitRuns(): void
    {
        // First request: the probe unit records Probe + Setup + Discover +
        // Capture + Rewrite into the shared state; the orchestrator mirrors
        // them onto the run and persists them.
        $firstState = new PipelineState();
        $writer = new RuntimeStateFakeStage(PipelineStageKey::Probe, $firstState);
        $writer->writesProbe = new ProbeResult(
            Origin::fromParts('https', 'Blog.Example.test', 8443),
            'https://blog.example.test/',
            4096,
            42,
        );
        $writer->writesSetup = new SetupResult('/tmp/uploads/cast-work/run-1');
        $writer->writesDiscover = new DiscoverResult(12);
        $writer->writesCapture = new CaptureSummary(10, 1, 1);
        $writer->writesRewrite = new RewriteSummary(7, 1);
        $tickA = $this->pipeline([PipelineStageKey::Probe->value => $writer], $firstState);
        $run = $this->startedRun('run-1');
        $tickA->perform($run, 1001);

        $saved = $this->repository->find('run-1');
        self::assertNotNull($saved);
        self::assertNotNull($saved->probe);
        self::assertNotNull($saved->setup);
        self::assertNotNull($saved->discover);
        self::assertSame(12, $saved->discover->enqueued);
        self::assertNotNull($saved->capture);
        self::assertSame(10, $saved->capture->done);
        self::assertNotNull($saved->rewrite);
        self::assertSame(7, $saved->rewrite->rewritten);
        self::assertSame(1, $saved->rewrite->passedThrough);

        // Second request: a brand-new shared PipelineState and a brand-new
        // orchestrator over the persisted run. The setup unit must observe the
        // persisted Rewrite value re-hydrated before it executes — proof that
        // a fresh WP-Cron request resumes instead of re-running rewrite.
        $secondState = new PipelineState();
        $observer = new RuntimeStateFakeStage(PipelineStageKey::Setup, $secondState);
        $tickB = $this->pipeline([PipelineStageKey::Setup->value => $observer], $secondState);
        $freshRun = $this->repository->find('run-1');
        self::assertNotNull($freshRun);

        $tickB->perform($freshRun, 1002);

        self::assertNotNull($observer->seenProbe);
        self::assertSame('https', $observer->seenProbe->origin->scheme());
        self::assertSame('Blog.Example.test', $observer->seenProbe->origin->host());
        self::assertSame(8443, $observer->seenProbe->origin->port());
        self::assertSame('https://blog.example.test/', $observer->seenProbe->finalUrl);
        self::assertNotNull($observer->seenSetup);
        self::assertSame('/tmp/uploads/cast-work/run-1', $observer->seenSetup->workDir);
        self::assertNotNull($observer->seenDiscover);
        self::assertSame(12, $observer->seenDiscover->enqueued);
        self::assertNotNull($observer->seenCapture);
        self::assertSame(10, $observer->seenCapture->done);
        self::assertSame(1, $observer->seenCapture->failed);
        self::assertSame(1, $observer->seenCapture->skipped);
        self::assertNotNull($observer->seenRewrite);
        self::assertSame(7, $observer->seenRewrite->rewritten);
        self::assertSame(1, $observer->seenRewrite->passedThrough);
    }

    public function testMirrorsRewriteResultBackToTheRunAndPersistsThem(): void
    {
        $state = new PipelineState();
        $writer = new RuntimeStateFakeStage(PipelineStageKey::Probe, $state);
        $writer->writesProbe = new ProbeResult(
            Origin::fromParts('https', 'blog.example.test', null),
            'https://blog.example.test/',
            2048,
            12,
        );
        $writer->writesSetup = new SetupResult('/work/run-1');
        $writer->writesDiscover = new DiscoverResult(7);
        $writer->writesCapture = new CaptureSummary(4, 2, 1);
        $writer->writesRewrite = new RewriteSummary(3, 1);
        $tick = $this->pipeline([PipelineStageKey::Probe->value => $writer], $state);
        $run = $this->startedRun('run-1');

        $tick->perform($run, 1001);

        // Mirrored onto the live aggregate...
        self::assertNotNull($run->probe);
        self::assertSame('https', $run->probe->origin->scheme());
        self::assertSame('blog.example.test', $run->probe->origin->host());
        self::assertNull($run->probe->origin->port());
        self::assertNotNull($run->setup);
        self::assertSame('/work/run-1', $run->setup->workDir);
        self::assertNotNull($run->discover);
        self::assertSame(7, $run->discover->enqueued);
        self::assertNotNull($run->capture);
        self::assertSame(4, $run->capture->done);
        self::assertSame(2, $run->capture->failed);
        self::assertSame(1, $run->capture->skipped);
        self::assertNotNull($run->rewrite);
        self::assertSame(3, $run->rewrite->rewritten);
        self::assertSame(1, $run->rewrite->passedThrough);

        // ...and persisted through the repository, round-tripping losslessly.
        $saved = $this->repository->find('run-1');
        self::assertNotNull($saved);
        self::assertNotNull($saved->probe);
        self::assertSame('https', $saved->probe->origin->scheme());
        self::assertSame('blog.example.test', $saved->probe->origin->host());
        self::assertNull($saved->probe->origin->port());
        self::assertNotNull($saved->setup);
        self::assertSame('/work/run-1', $saved->setup->workDir);
        self::assertNotNull($saved->discover);
        self::assertSame(7, $saved->discover->enqueued);
        self::assertNotNull($saved->capture);
        self::assertSame(4, $saved->capture->done);
        self::assertSame(2, $saved->capture->failed);
        self::assertSame(1, $saved->capture->skipped);
        self::assertNotNull($saved->rewrite);
        self::assertSame(3, $saved->rewrite->rewritten);
        self::assertSame(1, $saved->rewrite->passedThrough);
        self::assertSame($saved->toArray(), ExportRun::fromArray($saved->toArray())->toArray());
    }

    public function testFreshPipelineStateSeesPersistedPackBeforeTheUnitRuns(): void
    {
        // First request: the probe unit records the whole runtime state
        // including Pack into the shared state; the orchestrator mirrors them
        // onto the run and persists them.
        $firstState = new PipelineState();
        $writer = new RuntimeStateFakeStage(PipelineStageKey::Probe, $firstState);
        $writer->writesProbe = new ProbeResult(
            Origin::fromParts('https', 'Blog.Example.test', 8443),
            'https://blog.example.test/',
            4096,
            42,
        );
        $writer->writesSetup = new SetupResult('/tmp/uploads/cast-work/run-1');
        $writer->writesDiscover = new DiscoverResult(12);
        $writer->writesCapture = new CaptureSummary(10, 1, 1);
        $writer->writesRewrite = new RewriteSummary(7, 1);
        $writer->writesPack = new PackResult(
            PackStatus::Completed,
            42,
            0,
            12345,
            '/tmp/exports/run-1.zip',
            '/tmp/exports/manifest.json',
        );
        $tickA = $this->pipeline([PipelineStageKey::Probe->value => $writer], $firstState);
        $run = $this->startedRun('run-1');
        $tickA->perform($run, 1001);

        $saved = $this->repository->find('run-1');
        self::assertNotNull($saved);
        self::assertNotNull($saved->probe);
        self::assertNotNull($saved->setup);
        self::assertNotNull($saved->discover);
        self::assertSame(12, $saved->discover->enqueued);
        self::assertNotNull($saved->capture);
        self::assertSame(10, $saved->capture->done);
        self::assertNotNull($saved->rewrite);
        self::assertSame(7, $saved->rewrite->rewritten);
        self::assertSame(1, $saved->rewrite->passedThrough);
        self::assertNotNull($saved->pack);
        self::assertSame(PackStatus::Completed, $saved->pack->status);
        self::assertSame(42, $saved->pack->filesAdded);
        self::assertSame('/tmp/exports/run-1.zip', $saved->pack->zipPath);

        // Second request: a brand-new shared PipelineState and a brand-new
        // orchestrator over the persisted run. The setup unit must observe the
        // persisted Pack value re-hydrated before it executes — proof that a
        // fresh WP-Cron request resumes instead of re-packing.
        $secondState = new PipelineState();
        $observer = new RuntimeStateFakeStage(PipelineStageKey::Setup, $secondState);
        $tickB = $this->pipeline([PipelineStageKey::Setup->value => $observer], $secondState);
        $freshRun = $this->repository->find('run-1');
        self::assertNotNull($freshRun);

        $tickB->perform($freshRun, 1002);

        self::assertNotNull($observer->seenProbe);
        self::assertSame('https', $observer->seenProbe->origin->scheme());
        self::assertSame('Blog.Example.test', $observer->seenProbe->origin->host());
        self::assertSame(8443, $observer->seenProbe->origin->port());
        self::assertSame('https://blog.example.test/', $observer->seenProbe->finalUrl);
        self::assertNotNull($observer->seenSetup);
        self::assertSame('/tmp/uploads/cast-work/run-1', $observer->seenSetup->workDir);
        self::assertNotNull($observer->seenDiscover);
        self::assertSame(12, $observer->seenDiscover->enqueued);
        self::assertNotNull($observer->seenCapture);
        self::assertSame(10, $observer->seenCapture->done);
        self::assertSame(1, $observer->seenCapture->failed);
        self::assertSame(1, $observer->seenCapture->skipped);
        self::assertNotNull($observer->seenRewrite);
        self::assertSame(7, $observer->seenRewrite->rewritten);
        self::assertSame(1, $observer->seenRewrite->passedThrough);
        self::assertNotNull($observer->seenPack);
        self::assertSame(PackStatus::Completed, $observer->seenPack->status);
        self::assertSame(42, $observer->seenPack->filesAdded);
        self::assertSame(0, $observer->seenPack->filesSkipped);
        self::assertSame(12345, $observer->seenPack->bytesWritten);
        self::assertSame('/tmp/exports/run-1.zip', $observer->seenPack->zipPath);
        self::assertSame('/tmp/exports/manifest.json', $observer->seenPack->manifestPath);
    }

    public function testMirrorsPackResultBackToTheRunAndPersistsThem(): void
    {
        $state = new PipelineState();
        $writer = new RuntimeStateFakeStage(PipelineStageKey::Probe, $state);
        $writer->writesProbe = new ProbeResult(
            Origin::fromParts('https', 'blog.example.test', null),
            'https://blog.example.test/',
            2048,
            12,
        );
        $writer->writesSetup = new SetupResult('/work/run-1');
        $writer->writesDiscover = new DiscoverResult(7);
        $writer->writesCapture = new CaptureSummary(4, 2, 1);
        $writer->writesRewrite = new RewriteSummary(3, 1);
        $writer->writesPack = new PackResult(
            PackStatus::CompletedWithWarnings,
            10,
            2,
            999,
            '/tmp/exports/run-2.zip',
            '/tmp/exports/manifest.json',
            ['Skipped leak.txt: resolved outside the work directory jail'],
        );
        $tick = $this->pipeline([PipelineStageKey::Probe->value => $writer], $state);
        $run = $this->startedRun('run-1');

        $tick->perform($run, 1001);

        // Mirrored onto the live aggregate...
        self::assertNotNull($run->probe);
        self::assertSame('https', $run->probe->origin->scheme());
        self::assertSame('blog.example.test', $run->probe->origin->host());
        self::assertNull($run->probe->origin->port());
        self::assertNotNull($run->setup);
        self::assertSame('/work/run-1', $run->setup->workDir);
        self::assertNotNull($run->discover);
        self::assertSame(7, $run->discover->enqueued);
        self::assertNotNull($run->capture);
        self::assertSame(4, $run->capture->done);
        self::assertSame(2, $run->capture->failed);
        self::assertSame(1, $run->capture->skipped);
        self::assertNotNull($run->rewrite);
        self::assertSame(3, $run->rewrite->rewritten);
        self::assertSame(1, $run->rewrite->passedThrough);
        self::assertNotNull($run->pack);
        self::assertSame(PackStatus::CompletedWithWarnings, $run->pack->status);
        self::assertSame(10, $run->pack->filesAdded);
        self::assertSame(2, $run->pack->filesSkipped);
        self::assertSame(999, $run->pack->bytesWritten);
        self::assertSame('/tmp/exports/run-2.zip', $run->pack->zipPath);
        self::assertSame(['Skipped leak.txt: resolved outside the work directory jail'], $run->pack->warnings);

        // ...and persisted through the repository, round-tripping losslessly.
        $saved = $this->repository->find('run-1');
        self::assertNotNull($saved);
        self::assertNotNull($saved->probe);
        self::assertSame('https', $saved->probe->origin->scheme());
        self::assertSame('blog.example.test', $saved->probe->origin->host());
        self::assertNull($saved->probe->origin->port());
        self::assertNotNull($saved->setup);
        self::assertSame('/work/run-1', $saved->setup->workDir);
        self::assertNotNull($saved->discover);
        self::assertSame(7, $saved->discover->enqueued);
        self::assertNotNull($saved->capture);
        self::assertSame(4, $saved->capture->done);
        self::assertSame(2, $saved->capture->failed);
        self::assertSame(1, $saved->capture->skipped);
        self::assertNotNull($saved->rewrite);
        self::assertSame(3, $saved->rewrite->rewritten);
        self::assertSame(1, $saved->rewrite->passedThrough);
        self::assertNotNull($saved->pack);
        self::assertSame(PackStatus::CompletedWithWarnings, $saved->pack->status);
        self::assertSame(10, $saved->pack->filesAdded);
        self::assertSame(2, $saved->pack->filesSkipped);
        self::assertSame(999, $saved->pack->bytesWritten);
        self::assertSame('/tmp/exports/run-2.zip', $saved->pack->zipPath);
        self::assertSame('/tmp/exports/manifest.json', $saved->pack->manifestPath);
        self::assertSame(['Skipped leak.txt: resolved outside the work directory jail'], $saved->pack->warnings);
        self::assertSame($saved->toArray(), ExportRun::fromArray($saved->toArray())->toArray());
    }

    public function testFreshPipelineStateSeesPersistedWrapupBeforeTheUnitRuns(): void
    {
        // First request: the probe unit records the whole runtime state
        // including Wrapup into the shared state; the orchestrator mirrors them
        // onto the run and persists them.
        $firstState = new PipelineState();
        $writer = new RuntimeStateFakeStage(PipelineStageKey::Probe, $firstState);
        $writer->writesProbe = new ProbeResult(
            Origin::fromParts('https', 'Blog.Example.test', 8443),
            'https://blog.example.test/',
            4096,
            42,
        );
        $writer->writesSetup = new SetupResult('/tmp/uploads/cast-work/run-1');
        $writer->writesDiscover = new DiscoverResult(12);
        $writer->writesCapture = new CaptureSummary(10, 1, 1);
        $writer->writesRewrite = new RewriteSummary(7, 1);
        $writer->writesPack = new PackResult(
            PackStatus::Completed,
            42,
            0,
            12345,
            '/tmp/exports/run-1.zip',
            '/tmp/exports/manifest.json',
        );
        $writer->writesWrapup = WrapupResult::completed(true, ['pack warning']);
        $tickA = $this->pipeline([PipelineStageKey::Probe->value => $writer], $firstState);
        $run = $this->startedRun('run-1');
        $tickA->perform($run, 1001);

        $saved = $this->repository->find('run-1');
        self::assertNotNull($saved);
        self::assertNotNull($saved->wrapup);
        self::assertTrue($saved->wrapup->success);
        self::assertTrue($saved->wrapup->workDirDeleted);
        self::assertSame(['pack warning'], $saved->wrapup->warnings);

        // Second request: a brand-new shared PipelineState and a brand-new
        // orchestrator over the persisted run. The setup unit must observe the
        // persisted Wrapup value re-hydrated before it executes — proof that a
        // fresh WP-Cron request resumes instead of re-running wrap-up.
        $secondState = new PipelineState();
        $observer = new RuntimeStateFakeStage(PipelineStageKey::Setup, $secondState);
        $tickB = $this->pipeline([PipelineStageKey::Setup->value => $observer], $secondState);
        $freshRun = $this->repository->find('run-1');
        self::assertNotNull($freshRun);

        $tickB->perform($freshRun, 1002);

        self::assertNotNull($observer->seenWrapup);
        self::assertTrue($observer->seenWrapup->success);
        self::assertTrue($observer->seenWrapup->workDirDeleted);
        self::assertSame(['pack warning'], $observer->seenWrapup->warnings);
    }

    public function testMirrorsWrapupResultBackToTheRunAndPersistsThem(): void
    {
        $state = new PipelineState();
        $writer = new RuntimeStateFakeStage(PipelineStageKey::Probe, $state);
        $writer->writesProbe = new ProbeResult(
            Origin::fromParts('https', 'blog.example.test', null),
            'https://blog.example.test/',
            2048,
            12,
        );
        $writer->writesSetup = new SetupResult('/work/run-1');
        $writer->writesDiscover = new DiscoverResult(7);
        $writer->writesCapture = new CaptureSummary(4, 2, 1);
        $writer->writesRewrite = new RewriteSummary(3, 1);
        $writer->writesPack = new PackResult(
            PackStatus::CompletedWithWarnings,
            10,
            2,
            999,
            '/tmp/exports/run-2.zip',
            '/tmp/exports/manifest.json',
            ['Skipped leak.txt: resolved outside the work directory jail'],
        );
        $writer->writesWrapup = WrapupResult::completed(true, ['pack warning']);
        $writer->writesPublish = $this->publishBoundaryResult();
        $tick = $this->pipeline([PipelineStageKey::Probe->value => $writer], $state);
        $run = $this->startedRun('run-1');

        $tick->perform($run, 1001);

        // Mirrored onto the live aggregate...
        self::assertNotNull($run->wrapup);
        self::assertTrue($run->wrapup->success);
        self::assertTrue($run->wrapup->workDirDeleted);
        self::assertFalse($run->wrapup->hasIntegrityFailure());
        self::assertSame([], $run->wrapup->integrityErrors);
        self::assertNull($run->wrapup->deletionFailure);
        self::assertSame(['pack warning'], $run->wrapup->warnings);

        // ...and persisted through the repository, round-tripping losslessly.
        $saved = $this->repository->find('run-1');
        self::assertNotNull($saved);
        self::assertNotNull($saved->probe);
        self::assertSame('https', $saved->probe->origin->scheme());
        self::assertSame('blog.example.test', $saved->probe->origin->host());
        self::assertNull($saved->probe->origin->port());
        self::assertNotNull($saved->setup);
        self::assertSame('/work/run-1', $saved->setup->workDir);
        self::assertNotNull($saved->discover);
        self::assertSame(7, $saved->discover->enqueued);
        self::assertNotNull($saved->capture);
        self::assertSame(4, $saved->capture->done);
        self::assertSame(2, $saved->capture->failed);
        self::assertSame(1, $saved->capture->skipped);
        self::assertNotNull($saved->rewrite);
        self::assertSame(3, $saved->rewrite->rewritten);
        self::assertSame(1, $saved->rewrite->passedThrough);
        self::assertNotNull($saved->pack);
        self::assertSame(PackStatus::CompletedWithWarnings, $saved->pack->status);
        self::assertSame(['Skipped leak.txt: resolved outside the work directory jail'], $saved->pack->warnings);
        self::assertNotNull($saved->wrapup);
        self::assertTrue($saved->wrapup->success);
        self::assertTrue($saved->wrapup->workDirDeleted);
        self::assertSame(['pack warning'], $saved->wrapup->warnings);
        self::assertNotNull($saved->publish);
        self::assertSame('QmHash', $saved->publish->cid);
        self::assertSame('website-1', $saved->publish->websiteId);
        self::assertSame('k1-example.com', $saved->publish->ipnsKey);
        self::assertSame($saved->toArray(), ExportRun::fromArray($saved->toArray())->toArray());
    }

    public function testFreshPipelineStateSeesPersistedPublishBeforeTheUnitRuns(): void
    {
        $firstState = new PipelineState();
        $writer = new RuntimeStateFakeStage(PipelineStageKey::Probe, $firstState);
        $writer->writesProbe = new ProbeResult(
            Origin::fromParts('https', 'Blog.Example.test', 8443),
            'https://blog.example.test/',
            4096,
            42,
        );
        $writer->writesSetup = new SetupResult('/tmp/uploads/cast-work/run-1');
        $writer->writesDiscover = new DiscoverResult(12);
        $writer->writesCapture = new CaptureSummary(10, 1, 1);
        $writer->writesRewrite = new RewriteSummary(7, 1);
        $writer->writesPack = new PackResult(
            PackStatus::Completed,
            42,
            0,
            12345,
            '/tmp/exports/run-1.zip',
            '/tmp/exports/manifest.json',
        );
        $writer->writesWrapup = WrapupResult::completed(true, ['pack warning']);
        $writer->writesPublish = $this->publishBoundaryResult();
        $tickA = $this->pipeline([PipelineStageKey::Probe->value => $writer], $firstState);
        $run = $this->startedRun('run-1');
        $tickA->perform($run, 1001);

        $saved = $this->repository->find('run-1');
        self::assertNotNull($saved);
        self::assertNotNull($saved->publish);
        self::assertSame('QmHash', $saved->publish->cid);
        self::assertSame('website-1', $saved->publish->websiteId);
        self::assertSame('k1-example.com', $saved->publish->ipnsKey);

        // Second request: a brand-new shared PipelineState and a brand-new
        // orchestrator over the persisted run. The setup unit must observe the
        // persisted Publish value re-hydrated before it executes — proof that a
        // fresh WP-Cron request resumes instead of re-running publish.
        $secondState = new PipelineState();
        $observer = new RuntimeStateFakeStage(PipelineStageKey::Setup, $secondState);
        $tickB = $this->pipeline([PipelineStageKey::Setup->value => $observer], $secondState);
        $freshRun = $this->repository->find('run-1');
        self::assertNotNull($freshRun);

        $tickB->perform($freshRun, 1002);

        self::assertNotNull($observer->seenPublish);
        self::assertSame('QmHash', $observer->seenPublish->cid);
        self::assertSame('website-1', $observer->seenPublish->websiteId);
        self::assertSame('k1-example.com', $observer->seenPublish->ipnsKey);
    }

    public function testMirrorsPublishResultBackToTheRunAndPersistsThem(): void
    {
        $state = new PipelineState();
        $writer = new RuntimeStateFakeStage(PipelineStageKey::Probe, $state);
        $writer->writesProbe = new ProbeResult(
            Origin::fromParts('https', 'blog.example.test', null),
            'https://blog.example.test/',
            2048,
            12,
        );
        $writer->writesSetup = new SetupResult('/work/run-1');
        $writer->writesDiscover = new DiscoverResult(7);
        $writer->writesCapture = new CaptureSummary(4, 2, 1);
        $writer->writesRewrite = new RewriteSummary(3, 1);
        $writer->writesPack = new PackResult(
            PackStatus::CompletedWithWarnings,
            10,
            2,
            999,
            '/tmp/exports/run-2.zip',
            '/tmp/exports/manifest.json',
            ['Skipped leak.txt: resolved outside the work directory jail'],
        );
        $writer->writesWrapup = WrapupResult::completed(true, ['pack warning']);
        $writer->writesPublish = $this->publishBoundaryResult();
        $tick = $this->pipeline([PipelineStageKey::Probe->value => $writer], $state);
        $run = $this->startedRun('run-1');

        $tick->perform($run, 1001);

        // Mirrored onto the live aggregate...
        self::assertNotNull($run->publish);
        self::assertSame('QmHash', $run->publish->cid);
        self::assertSame('website-1', $run->publish->websiteId);
        self::assertSame('k1-example.com', $run->publish->ipnsKey);
        // A completed publish also wires the run's top-level publish identifiers
        // so status reports carry the CID/website/IPNS identity.
        self::assertSame('QmHash', $run->publishCid);
        self::assertSame('website-1', $run->websiteId);
        self::assertSame('k1-example.com', $run->ipnsKey);

        // ...and persisted through the repository, round-tripping losslessly.
        $saved = $this->repository->find('run-1');
        self::assertNotNull($saved);
        self::assertNotNull($saved->probe);
        self::assertSame('https', $saved->probe->origin->scheme());
        self::assertSame('blog.example.test', $saved->probe->origin->host());
        self::assertNull($saved->probe->origin->port());
        self::assertNotNull($saved->setup);
        self::assertSame('/work/run-1', $saved->setup->workDir);
        self::assertNotNull($saved->discover);
        self::assertSame(7, $saved->discover->enqueued);
        self::assertNotNull($saved->capture);
        self::assertSame(4, $saved->capture->done);
        self::assertSame(2, $saved->capture->failed);
        self::assertSame(1, $saved->capture->skipped);
        self::assertNotNull($saved->rewrite);
        self::assertSame(3, $saved->rewrite->rewritten);
        self::assertSame(1, $saved->rewrite->passedThrough);
        self::assertNotNull($saved->pack);
        self::assertSame(PackStatus::CompletedWithWarnings, $saved->pack->status);
        self::assertSame(['Skipped leak.txt: resolved outside the work directory jail'], $saved->pack->warnings);
        self::assertNotNull($saved->wrapup);
        self::assertTrue($saved->wrapup->success);
        self::assertTrue($saved->wrapup->workDirDeleted);
        self::assertSame(['pack warning'], $saved->wrapup->warnings);
        self::assertNotNull($saved->publish);
        self::assertSame('QmHash', $saved->publish->cid);
        self::assertSame('website-1', $saved->publish->websiteId);
        self::assertSame('k1-example.com', $saved->publish->ipnsKey);
        self::assertSame('QmHash', $saved->publishCid);
        self::assertSame('website-1', $saved->websiteId);
        self::assertSame('k1-example.com', $saved->ipnsKey);
        self::assertSame($saved->toArray(), ExportRun::fromArray($saved->toArray())->toArray());
    }

    public function testResumablePublishDoesNotWireTopLevelIdentifiersAsFalseCompletion(): void
    {
        // A readiness timeout leaves the CID/website/IPNS owned but not
        // confirmed live, so the boundary is Resumable. The orchestrator must
        // persist that boundary for later retry but must never promote those
        // identifiers to the run's top-level publish fields, which would
        // falsely advertise a completed publish.
        $state = new PipelineState();
        $writer = new RuntimeStateFakeStage(PipelineStageKey::Probe, $state);
        $writer->writesPublish = PublishBoundaryResult::resumable(
            'QmHash',
            'website-1',
            'k1-example.com',
            'Website did not confirm serving the new CID before the readiness budget expired.',
        );
        $tick = $this->pipeline([PipelineStageKey::Probe->value => $writer], $state);
        $run = $this->startedRun('run-1');

        $tick->perform($run, 1001);

        // The boundary record is persisted so a later tick resumes...
        self::assertNotNull($run->publish);
        self::assertSame(PublishBoundaryStatus::Resumable, $run->publish->status);
        self::assertSame('QmHash', $run->publish->cid);
        self::assertSame('website-1', $run->publish->websiteId);
        self::assertSame('k1-example.com', $run->publish->ipnsKey);

        // ...but the run's top-level publish identity stays untouched: no
        // false completion.
        self::assertNull($run->publishCid);
        self::assertNull($run->websiteId);
        self::assertNull($run->ipnsKey);

        $saved = $this->repository->find('run-1');
        self::assertNotNull($saved);
        self::assertNotNull($saved->publish);
        self::assertSame(PublishBoundaryStatus::Resumable, $saved->publish->status);
        self::assertNull($saved->publishCid);
        self::assertNull($saved->websiteId);
        self::assertNull($saved->ipnsKey);
        self::assertSame($saved->toArray(), ExportRun::fromArray($saved->toArray())->toArray());
    }

    public function testConstructsSetupStagePerRunAtTickTimeWithTheCurrentRunId(): void
    {
        $built = [];
        $setup = new FakePipelineStage(PipelineStageKey::Setup);
        $setup->respond = static fn (): StageResult => StageResult::done('');
        $stages = [];
        foreach (PipelineStageKey::cases() as $key) {
            $stages[$key->value] = new UnwiredStage($key);
        }
        unset($stages[PipelineStageKey::Setup->value]);
        $context = new PipelineContext(
            runs: $this->repository,
            stages: $stages,
            stageFactories: [
                PipelineStageKey::Setup->value => static function (string $runId) use ($setup, &$built): PipelineStage {
                    $built[] = $runId;

                    return $setup;
                },
            ],
        );
        $tick = new ExportPipelineTick($context);

        $run = $this->startedRun('run-1');
        $run->advanceStage(RunStage::Exporting, at: 1001);
        $run->recordResumeCursor('setup|', at: 1001);
        $this->repository->save($run);

        $tick->perform($run, 1002);

        self::assertSame(['run-1'], $built);
        self::assertCount(1, $setup->calls);
        self::assertSame(PipelineStageKey::Setup, $setup->calls[0]['key']);
    }

    public function testPipelineContextRequiresEveryWiredStage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PipelineContext(runs: $this->repository, stages: [
            PipelineStageKey::Probe->value => new UnwiredStage(PipelineStageKey::Probe),
        ]);
    }
}
