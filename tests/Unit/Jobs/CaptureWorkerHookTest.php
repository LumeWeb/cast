<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use Closure;
use ComposePress\Core\Testing\RecordingHooks;
use LumeWeb\Cast\Export\CaptureResponse;
use LumeWeb\Cast\Export\InMemoryRunRepository;
use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\JailedDiskAssetSource;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\ProbeResult;
use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Export\RunStage;
use LumeWeb\Cast\Export\SetupResult;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Export\WorkItemStatus;
use LumeWeb\Cast\Jobs\CaptureWorker;
use LumeWeb\Cast\Jobs\CaptureWorkerScheduler;
use LumeWeb\Cast\Jobs\ContentPublishScheduler;
use LumeWeb\Cast\Jobs\ExportTickRunner;
use LumeWeb\Cast\Jobs\FixedClock;
use LumeWeb\Cast\Jobs\InMemoryIdentityGateway;
use LumeWeb\Cast\Jobs\InMemoryLock;
use LumeWeb\Cast\Jobs\InMemoryPublishModeStore;
use LumeWeb\Cast\Jobs\InMemoryScheduler;
use LumeWeb\Cast\Jobs\JobsHookSubscriber;
use LumeWeb\Cast\Jobs\PublishMode;
use LumeWeb\Cast\Jobs\TickConfig;
use LumeWeb\Cast\Tests\Unit\Export\FakeCaptureEnvironment;
use LumeWeb\Cast\Tests\Unit\Export\FakeCaptureTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The capture-worker action hook: the handler runs exactly one bounded
 * one-item worker for the (runId, slot) event identity, then coaxes the
 * normal auto-tick so the coordinator keeps the run moving. Duplicate and
 * stale worker events stay harmless: they capture nothing twice and arm no
 * extra ticks.
 */
final class CaptureWorkerHookTest extends TestCase
{
    private const RUN = 'run-1';
    private const ORIGIN = 'https://blog.example.test/';

    private FixedClock $clock;
    private InMemoryLock $lock;
    private InMemoryRunRepository $runs;
    private InMemoryWorkItemRepository $items;
    private InMemoryScheduler $scheduler;
    private InMemoryIdentityGateway $identity;
    private ContentPublishScheduler $content;
    private FakeCaptureTransport $transport;
    private JobsHookSubscriber $subscriber;

    private string $workDir;
    private string $jailRoot;

    /**
     * @var list<array{string, int}> (runId, slot) pairs the worker factory was asked to build
     */
    private array $factoryCalls = [];

    protected function setUp(): void
    {
        $this->clock = new FixedClock(1000);
        $this->lock = new InMemoryLock($this->clock);
        $this->runs = new InMemoryRunRepository();
        $this->items = new InMemoryWorkItemRepository(clock: fn (): int => $this->clock->now());
        $this->scheduler = new InMemoryScheduler();
        $this->identity = new InMemoryIdentityGateway(true);
        $this->workDir = sys_get_temp_dir() . '/cast-worker-hook-' . bin2hex(random_bytes(6));
        $this->jailRoot = sys_get_temp_dir() . '/cast-worker-hook-jail-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0777, true);
        mkdir($this->jailRoot, 0777, true);
        $this->transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], $this->html('a')),
        ]);

        $tick = new FakeTick();
        $runner = new ExportTickRunner(
            clock: $this->clock,
            lock: $this->lock,
            repository: $this->runs,
            tick: $tick,
            identity: $this->identity,
            config: new TickConfig(lockKey: 'cast:export-tick', lockTtlSeconds: 60),
        );
        $this->content = new ContentPublishScheduler(
            clock: $this->clock,
            repository: $this->runs,
            scheduler: $this->scheduler,
            identity: $this->identity,
            modeStore: new InMemoryPublishModeStore(PublishMode::OnUpdate),
            workItems: $this->items,
        );
        $this->subscriber = new JobsHookSubscriber(
            $runner,
            $this->content,
            $this->scheduler,
            $this->clock,
            rearmDelaySeconds: 2,
            captureWorkerFactory: $this->workerFactory(),
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workDir);
        $this->removeTree($this->jailRoot);
    }

    public function testRegistersTheCaptureWorkerActionWhenWired(): void
    {
        $hooks = new RecordingHooks();
        $this->subscriber->subscribe($hooks);

        self::assertSame([
            ContentPublishScheduler::AUTO_HOOK,
            ContentPublishScheduler::FOLLOW_UP_HOOK,
            JobsHookSubscriber::TRANSITION_HOOK,
            CaptureWorkerScheduler::WORKER_HOOK,
        ], $hooks->actionNames());
        self::assertSame([], $hooks->filterNames());
    }

    public function testCaptureWorkerActionIsRegisteredAtPriority10WithTwoArguments(): void
    {
        // The worker event carries a (runId, slot) pair: the registration must
        // accept 2 arguments (the default of 1 would drop the slot) at the
        // standard priority 10, so fanout ordering stays WP-default.
        $hooks = new RecordingHooks();
        $this->subscriber->subscribe($hooks);

        $registrations = $hooks->actionsFor(CaptureWorkerScheduler::WORKER_HOOK);
        self::assertCount(1, $registrations, 'the worker action is registered exactly once');
        self::assertSame(10, $registrations[0]->priority, 'the worker action keeps the default priority 10');
        self::assertSame(2, $registrations[0]->arguments, 'the worker action must accept both (runId, slot)');
    }

    public function testUnwiredSubscriberRegistersNoCaptureWorkerAction(): void
    {
        // The pre-worker composition: no factory wired, so the worker action
        // is not registered and the subscriber stays the tick/follow-up/
        // transition subscriber it was before workers existed.
        $tick = new FakeTick();
        $runner = new ExportTickRunner(
            clock: $this->clock,
            lock: $this->lock,
            repository: $this->runs,
            tick: $tick,
            identity: $this->identity,
            config: new TickConfig(lockKey: 'cast:export-tick', lockTtlSeconds: 60),
        );
        $subscriber = new JobsHookSubscriber(
            $runner,
            $this->content,
            $this->scheduler,
            $this->clock,
            rearmDelaySeconds: 2,
        );

        $hooks = new RecordingHooks();
        $subscriber->subscribe($hooks);

        self::assertNotContains(CaptureWorkerScheduler::WORKER_HOOK, $hooks->actionNames());
    }

    public function testWorkerEventCapturesAtMostOneItemThroughASlotSpecificWorker(): void
    {
        $this->runAtCapture();
        for ($i = 0; $i < 3; ++$i) {
            $this->insert(sprintf('https://blog.example.test/page-%d/', $i));
        }
        $this->transport = new FakeCaptureTransport(array_fill(
            0,
            3,
            CaptureResponse::withString(200, [], $this->html('a')),
        ));

        $this->subscriber->runCaptureWorker(self::RUN, 2);

        self::assertSame([[self::RUN, 2]], $this->factoryCalls, 'the handler builds the worker from the (runId, slot) event identity');
        self::assertSame(1, $this->items->countByStatus(self::RUN, WorkItemStatus::Done), 'one event captures at most one item');
        self::assertSame(2, $this->items->countByStatus(self::RUN, WorkItemStatus::Queued), 'the rest stays queued for the tick and later workers');
    }

    public function testWorkerEventCoaxesExactlyOneAutoTickAfterACapture(): void
    {
        $this->runAtCapture();
        $this->insert('https://blog.example.test/a/');

        $this->subscriber->runCaptureWorker(self::RUN, 1);

        // The captured item needs the coordinator to keep the run moving:
        // exactly one normal auto-tick is armed on the short rearm cadence.
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame(1002, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testDuplicateWorkerEventsCaptureNothingTwiceAndCoaxNoSecondTick(): void
    {
        // The same fanout armed twice (two slots, one queued item): the first
        // worker captures, the second finds an empty queue. One capture, one
        // tick — no duplicate work, no stacked auto-ticks.
        $this->runAtCapture();
        $this->insert('https://blog.example.test/a/');

        $this->subscriber->runCaptureWorker(self::RUN, 1);
        $this->subscriber->runCaptureWorker(self::RUN, 2);

        self::assertSame(1, $this->items->countByStatus(self::RUN, WorkItemStatus::Done));
        self::assertSame(1, $this->scheduler->count(), 'no stacked auto-ticks from a duplicate fanout');
        self::assertSame(1002, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testWorkerCoaxDedupesAgainstAPendingAutoTick(): void
    {
        // A tick is already pending: the worker's coax must not move or stack
        // it. The loop keeps the one event it already has.
        $this->runAtCapture();
        $this->insert('https://blog.example.test/a/');
        $this->scheduler->scheduleSingle(ContentPublishScheduler::AUTO_HOOK, 1005);

        $this->subscriber->runCaptureWorker(self::RUN, 1);

        self::assertSame(1, $this->scheduler->count());
        self::assertSame(1005, $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK), 'the pending tick is kept, not replaced');
    }

    public function testStaleWorkerEventForAMovedRunIsHarmless(): void
    {
        $this->runAtCapture();
        $this->insert('https://blog.example.test/a/');
        $run = $this->runs->find(self::RUN);
        self::assertNotNull($run);
        // The run already crossed to rewrite: the armed worker event is stale.
        $run->recordResumeCursor('rewrite|', at: 1001);
        $this->runs->save($run);

        $this->subscriber->runCaptureWorker(self::RUN, 1);

        self::assertSame(1, $this->items->countByStatus(self::RUN, WorkItemStatus::Queued), 'a stale event captures nothing');
        self::assertSame(0, $this->scheduler->count(), 'a stale event coaxes no tick');
    }

    public function testStaleWorkerEventForATerminalRunIsHarmless(): void
    {
        $this->runAtCapture();
        $this->insert('https://blog.example.test/a/');
        $run = $this->runs->find(self::RUN);
        self::assertNotNull($run);
        $run->complete(at: 1001);
        $this->runs->save($run);

        $this->subscriber->runCaptureWorker(self::RUN, 1);

        self::assertSame(1, $this->items->countByStatus(self::RUN, WorkItemStatus::Queued), 'a stale event captures nothing');
        self::assertSame(0, $this->scheduler->count(), 'a stale event coaxes no tick');
    }

    /**
     * @param string|null $runId
     */
    #[DataProvider('malformedWorkerArgs')]
    public function testMalformedWorkerArgsAreHarmless(?string $runId, int $slot): void
    {
        $this->runAtCapture();
        $this->insert('https://blog.example.test/a/');

        $this->subscriber->runCaptureWorker($runId, $slot);

        self::assertSame([], $this->factoryCalls, 'malformed args build no worker');
        self::assertSame(0, $this->scheduler->count(), 'malformed args coax no tick');
        self::assertSame(1, $this->items->countByStatus(self::RUN, WorkItemStatus::Queued), 'malformed args capture nothing');
    }

    /**
     * @return array<string, array{string|null, int}>
     */
    public static function malformedWorkerArgs(): array
    {
        return [
            'missing run id' => [null, 1],
            'empty run id' => ['', 1],
            'slot below one' => [self::RUN, 0],
        ];
    }

    public function testUnwiredSubscriberIgnoresWorkerEventsSafely(): void
    {
        // An event reaching an unwired subscriber (e.g. a site that booted
        // before the wiring) must do nothing: no worker, no capture, no tick.
        $tick = new FakeTick();
        $runner = new ExportTickRunner(
            clock: $this->clock,
            lock: $this->lock,
            repository: $this->runs,
            tick: $tick,
            identity: $this->identity,
            config: new TickConfig(lockKey: 'cast:export-tick', lockTtlSeconds: 60),
        );
        $subscriber = new JobsHookSubscriber(
            $runner,
            $this->content,
            $this->scheduler,
            $this->clock,
            rearmDelaySeconds: 2,
        );
        $this->runAtCapture();
        $this->insert('https://blog.example.test/a/');

        $subscriber->runCaptureWorker(self::RUN, 1);

        self::assertSame(1, $this->items->countByStatus(self::RUN, WorkItemStatus::Queued));
        self::assertSame(0, $this->scheduler->count());
    }

    /**
     * The production-shaped factory: one real one-item worker per (runId,
     * slot), each with a slot-specific claim token so duplicate fanout never
     * steals a live lease.
     */
    private function workerFactory(): Closure
    {
        return function (string $runId, int $slot): CaptureWorker {
            $this->factoryCalls[] = [$runId, $slot];

            return new CaptureWorker(
                $this->runs,
                $this->items,
                new FakeCaptureEnvironment($this->transport, new JailedDiskAssetSource($this->jailRoot)),
                workerToken: sprintf('%s-%d', CaptureWorker::DEFAULT_WORKER_TOKEN, $slot),
            );
        };
    }

    /**
     * A running run positioned at the capture boundary with probe and setup
     * recorded — the exact state the coordinator tick leaves behind while
     * capture is in flight.
     */
    private function runAtCapture(): void
    {
        $run = \LumeWeb\Cast\Export\ExportRun::create(self::RUN, new RunSettings(hostname: 'blog.example.test'), at: 1000);
        $run->start(at: 1000);
        $run->advanceStage(RunStage::Exporting, at: 1000);
        $origin = Origin::fromUrl((new UrlCanonicalizer())->canonicalize(self::ORIGIN));
        $run->recordProbe(new ProbeResult($origin, self::ORIGIN, 2048, 5), at: 1000);
        $run->recordSetup(new SetupResult($this->workDir), at: 1000);
        $run->recordResumeCursor('capture|', at: 1000);
        $this->runs->save($run);
    }

    private function insert(string $url): void
    {
        $this->items->insertCanonical(self::RUN, (new WorkItemFactory())->fromString($url));
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
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
