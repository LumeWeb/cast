<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Export\CaptureResponse;
use LumeWeb\Cast\Export\JailedDiskAssetSource;
use LumeWeb\Cast\Export\InMemoryRunRepository;
use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\ProbeResult;
use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Export\RunStage;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Export\SetupResult;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Export\WorkItemRepository;
use LumeWeb\Cast\Export\WorkItemStatus;
use LumeWeb\Cast\Jobs\CaptureWorker;
use LumeWeb\Cast\Jobs\CaptureWorkerOutcomeKind;
use LumeWeb\Cast\Tests\Unit\Export\FakeCaptureEnvironment;
use LumeWeb\Cast\Tests\Unit\Export\FakeCaptureTransport;
use PHPUnit\Framework\TestCase;

/**
 * The one-item capture worker. Invariant under test: the worker never
 * mutates the run aggregate; stage transitions stay with the coordinator
 * tick.
 */
final class CaptureWorkerTest extends TestCase
{
    private const RUN = 'run-1';
    private const ORIGIN = 'https://blog.example.test/';

    private int $now;
    private string $workDir;
    private string $jailRoot;
    private InMemoryRunRepository $runs;
    private InMemoryWorkItemRepository $items;
    private WorkItemFactory $factory;

    protected function setUp(): void
    {
        $this->now = 1_000_000;
        $this->workDir = sys_get_temp_dir() . '/cast-worker-' . bin2hex(random_bytes(6));
        $this->jailRoot = sys_get_temp_dir() . '/cast-worker-jail-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0777, true);
        mkdir($this->jailRoot, 0777, true);
        $this->runs = new InMemoryRunRepository();
        $this->items = new InMemoryWorkItemRepository(clock: function (): int {
            return $this->now;
        });
        $this->factory = new WorkItemFactory();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workDir);
        $this->removeTree($this->jailRoot);
    }

    public function testCapturesExactlyOneQueuedItemAndAppliesItsOutcome(): void
    {
        $this->runAtCapture();
        $this->insert('https://blog.example.test/a/');
        $this->insert('https://blog.example.test/b/');
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], $this->html('a')),
        ]);

        $outcome = $this->worker('cast-capture-1', $transport)->work(self::RUN);

        self::assertSame(CaptureWorkerOutcomeKind::Captured, $outcome->kind);
        self::assertSame($this->factory->fromString('https://blog.example.test/a/')->identity(), $outcome->identity);
        self::assertSame(['https://blog.example.test/a/'], $transport->requested);
        self::assertSame(1, $this->items->countByStatus(self::RUN, WorkItemStatus::Done));
        self::assertSame(1, $this->items->countByStatus(self::RUN, WorkItemStatus::Queued), 'the second item is left queued');
        self::assertSame($this->html('a'), file_get_contents($this->workDir . '/a/index.html'));
    }

    public function testMissingRunExitsHarmlessly(): void
    {
        $this->insertInto('run-gone', 'https://blog.example.test/a/');
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], $this->html('a')),
        ]);

        $outcome = $this->worker('cast-capture-1')->work('run-gone');

        self::assertSame(CaptureWorkerOutcomeKind::SkippedMissing, $outcome->kind);
        self::assertNull($outcome->identity);
        self::assertSame([], $transport->requested);
        self::assertSame(1, $this->items->countByStatus('run-gone', WorkItemStatus::Queued), 'nothing is claimed for an unknown run');
    }

    public function testTerminalRunExitsHarmlesslyWithoutClaiming(): void
    {
        $run = $this->runAtCapture();
        $run->complete(at: $this->now);
        $this->runs->save($run);
        $this->insert('https://blog.example.test/a/');

        $outcome = $this->worker('cast-capture-1')->work(self::RUN);

        self::assertSame(CaptureWorkerOutcomeKind::SkippedTerminal, $outcome->kind);
        self::assertSame(1, $this->items->countByStatus(self::RUN, WorkItemStatus::Queued));
    }

    public function testSupersededRunExitsHarmlesslyAndIsNeverCancelled(): void
    {
        $run = $this->runAtCapture();
        $run->supersede(at: $this->now);
        $this->runs->save($run);
        $this->insert('https://blog.example.test/a/');

        $outcome = $this->worker('cast-capture-1')->work(self::RUN);

        self::assertSame(CaptureWorkerOutcomeKind::SkippedSuperseded, $outcome->kind);
        // Cancellation of a superseded run belongs to the coordinator tick:
        // the worker leaves the run exactly as it found it.
        $stored = $this->runs->find(self::RUN);
        self::assertNotNull($stored);
        self::assertSame(RunStatus::Running, $stored->status);
        self::assertTrue($stored->superseded);
        self::assertSame(1, $this->items->countByStatus(self::RUN, WorkItemStatus::Queued));
    }

    public function testRunNotAtCaptureExitsWithoutClaiming(): void
    {
        foreach (['probe|', 'discover|', 'rewrite|'] as $cursor) {
            $this->resetStores();
            $run = $this->runningExportingRun();
            $run->recordResumeCursor($cursor, at: $this->now);
            $this->runs->save($run);
            $this->insert('https://blog.example.test/a/');

            $outcome = $this->worker('cast-capture-1')->work(self::RUN);

            self::assertSame(CaptureWorkerOutcomeKind::SkippedNotAtCapture, $outcome->kind, $cursor);
            self::assertSame(1, $this->items->countByStatus(self::RUN, WorkItemStatus::Queued), $cursor);
        }

        // A paused run positioned at capture is not capture work either: the
        // operator's pause must not be raced by a worker.
        $this->resetStores();
        $run = $this->runAtCapture();
        $run->pause(at: $this->now);
        $this->runs->save($run);
        $this->insert('https://blog.example.test/a/');

        $outcome = $this->worker('cast-capture-1')->work(self::RUN);

        self::assertSame(CaptureWorkerOutcomeKind::SkippedNotAtCapture, $outcome->kind, 'paused at capture');
        self::assertSame(1, $this->items->countByStatus(self::RUN, WorkItemStatus::Queued), 'paused at capture');
    }

    private function resetStores(): void
    {
        $this->runs = new InMemoryRunRepository();
        $this->items = new InMemoryWorkItemRepository(clock: function (): int {
            return $this->now;
        });
    }

    public function testRunAtCaptureWithoutProbeOrSetupExitsNotReady(): void
    {
        $run = $this->runningExportingRun();
        $run->recordResumeCursor('capture|', at: $this->now);
        // No probe/setup recorded on the run: the worker cannot build a
        // capture service and exits before touching the queue.
        $this->runs->save($run);
        $this->insert('https://blog.example.test/a/');

        $outcome = $this->worker('cast-capture-1')->work(self::RUN);

        self::assertSame(CaptureWorkerOutcomeKind::SkippedNotReady, $outcome->kind);
        self::assertSame(1, $this->items->countByStatus(self::RUN, WorkItemStatus::Queued));
    }

    public function testEmptyQueueExitsIdleWithoutAnyCapture(): void
    {
        $this->runAtCapture();
        $transport = new FakeCaptureTransport([]);

        $outcome = $this->worker('cast-capture-1')->work(self::RUN);

        self::assertSame(CaptureWorkerOutcomeKind::SkippedIdle, $outcome->kind);
        self::assertNull($outcome->identity);
        self::assertSame([], $transport->requested);
    }

    public function testScheduledRetryNotYetDueIsNotClaimed(): void
    {
        $this->runAtCapture();
        $item = $this->factory->fromString('https://blog.example.test/a/');
        $this->items->insertCanonical(self::RUN, $item);
        $this->items->claimNext(self::RUN, 'cast-capture-1');
        $this->items->scheduleRetry(self::RUN, $item->urlHash(), 60);

        $tooEarly = $this->worker('cast-capture-1')->work(self::RUN);

        self::assertSame(CaptureWorkerOutcomeKind::SkippedIdle, $tooEarly->kind, 'a retry delay that has not passed is not claimable work');
        self::assertSame(0, $this->items->countByStatus(self::RUN, WorkItemStatus::Processing));

        $this->now += 61;
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], $this->html('a')),
        ]);
        $due = $this->worker('cast-capture-1', $transport)->work(self::RUN);

        self::assertSame(CaptureWorkerOutcomeKind::Captured, $due->kind);
    }

    public function testLiveLeaseIsNeverStolenByAnotherWorkerAndAnExpiredOneIsReclaimed(): void
    {
        $this->runAtCapture();
        $this->insert('https://blog.example.test/a/');

        $this->items->claimNext(self::RUN, 'cast-capture-a');

        $b = $this->worker('cast-capture-b')->work(self::RUN);

        self::assertSame(CaptureWorkerOutcomeKind::SkippedIdle, $b->kind, 'a live lease is never stolen, so duplicate fanout captures nothing twice');
        self::assertSame(1, $this->items->countByStatus(self::RUN, WorkItemStatus::Processing));

        $this->now += WorkItemRepository::DEFAULT_LEASE_SECONDS + 1;
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], $this->html('a')),
        ]);
        $c = $this->worker('cast-capture-b', $transport)->work(self::RUN);

        self::assertSame(CaptureWorkerOutcomeKind::Captured, $c->kind);
        self::assertSame(1, $this->items->countByStatus(self::RUN, WorkItemStatus::Done));
        self::assertSame(2, $this->items->attemptCountOf(self::RUN, $this->hash('https://blog.example.test/a/')), 'the reclaim counts as a second attempt');
    }

    public function testWorkerNeverMutatesTheRun(): void
    {
        $this->runAtCapture();
        $this->insert('https://blog.example.test/a/');
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], $this->html('a')),
        ]);
        $before = $this->runs->find(self::RUN);
        self::assertNotNull($before);
        $beforeArray = $before->toArray();

        $outcome = $this->worker('cast-capture-1', $transport)->work(self::RUN);

        self::assertSame(CaptureWorkerOutcomeKind::Captured, $outcome->kind);
        $after = $this->runs->find(self::RUN);
        self::assertNotNull($after);
        self::assertSame($beforeArray, $after->toArray(), 'the run aggregate is byte-identical after a worker capture');
        self::assertNull($after->capture, 'the worker never writes the run capture summary');
    }

    public function testWorkerDoesNotAdvanceToRewriteWhenTheQueueDrains(): void
    {
        $this->runAtCapture();
        $this->insert('https://blog.example.test/a/');
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], $this->html('a')),
        ]);

        $first = $this->worker('cast-capture-1', $transport)->work(self::RUN);
        $second = $this->worker('cast-capture-1', $transport)->work(self::RUN);

        self::assertSame(CaptureWorkerOutcomeKind::Captured, $first->kind);
        // The queue is drained, but closing the capture stage (its fixed
        // point, summary and the move to rewrite) is the tick's job.
        self::assertSame(CaptureWorkerOutcomeKind::SkippedIdle, $second->kind);
        $run = $this->runs->find(self::RUN);
        self::assertNotNull($run);
        self::assertSame('capture|', $run->resumeCursor);
        self::assertSame(RunStage::Exporting, $run->stage);
        self::assertSame(RunStatus::Running, $run->status);
        self::assertNull($run->capture);
    }

    public function testNotFoundOutcomeMarksTheClaimedItemFailed(): void
    {
        $this->runAtCapture();
        $this->insert('https://blog.example.test/gone/');
        $transport = new FakeCaptureTransport([CaptureResponse::empty(404)]);

        $outcome = $this->worker('cast-capture-1', $transport)->work(self::RUN);

        self::assertSame(CaptureWorkerOutcomeKind::Captured, $outcome->kind, 'a captured-and-decided item is still a worker capture');
        self::assertSame(1, $this->items->countByStatus(self::RUN, WorkItemStatus::Failed));
        self::assertSame(0, $this->items->pendingCount(self::RUN));
    }

    private function worker(string $token, ?FakeCaptureTransport $transport = null): CaptureWorker
    {
        $transport ??= new FakeCaptureTransport([]);

        return new CaptureWorker(
            $this->runs,
            $this->items,
            $this->environment($transport),
            workerToken: $token,
        );
    }

    private function environment(FakeCaptureTransport $transport): FakeCaptureEnvironment
    {
        return new FakeCaptureEnvironment($transport, new JailedDiskAssetSource($this->jailRoot));
    }

    /**
     * A running run positioned at the capture boundary with probe and setup
     * recorded — the exact state the coordinator tick leaves behind while
     * capture is in flight.
     */
    private function runAtCapture(): \LumeWeb\Cast\Export\ExportRun
    {
        $run = $this->runningExportingRun();
        $origin = Origin::fromUrl((new UrlCanonicalizer())->canonicalize(self::ORIGIN));
        $run->recordProbe(new ProbeResult($origin, self::ORIGIN, 2048, 5), at: $this->now);
        $run->recordSetup(new SetupResult($this->workDir), at: $this->now);
        $run->recordResumeCursor('capture|', at: $this->now);
        $this->runs->save($run);

        return $run;
    }

    private function runningExportingRun(): \LumeWeb\Cast\Export\ExportRun
    {
        $run = \LumeWeb\Cast\Export\ExportRun::create(self::RUN, new RunSettings(hostname: 'blog.example.test'), at: $this->now);
        $run->start(at: $this->now);
        $run->advanceStage(RunStage::Exporting, at: $this->now);
        $this->runs->save($run);

        return $run;
    }

    private function insert(string $url): void
    {
        $this->insertInto(self::RUN, $url);
    }

    private function insertInto(string $runId, string $url): void
    {
        $this->items->insertCanonical($runId, $this->factory->fromString($url));
    }

    private function hash(string $url): string
    {
        return md5($this->factory->fromString($url)->identity());
    }

    private function html(string $label): string
    {
        return '<html><head><title>' . $label . '</title></head><body>' . str_repeat('<p>content</p>', 120) . '</body></html>';
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
