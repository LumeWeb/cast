<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

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
use LumeWeb\Cast\Jobs\CaptureWorkerOutcomeKind;
use LumeWeb\Cast\Jobs\CaptureWorkerScheduler;
use LumeWeb\Cast\Jobs\InMemoryScheduler;
use LumeWeb\Cast\Tests\Unit\Export\FakeCaptureEnvironment;
use LumeWeb\Cast\Tests\Unit\Export\FakeCaptureTransport;
use PHPUnit\Framework\TestCase;

/**
 * Bounded capture-worker fanout: re-arming an already-armed run never
 * stacks duplicate events, and a duplicate fanout never captures an item
 * twice.
 */
final class CaptureWorkerSchedulerTest extends TestCase
{
    private const RUN = 'run-1';
    private const ORIGIN = 'https://blog.example.test/';

    private int $now;
    private string $workDir;
    private string $jailRoot;
    private InMemoryRunRepository $runs;
    private InMemoryWorkItemRepository $items;
    private InMemoryScheduler $scheduler;
    private WorkItemFactory $factory;

    protected function setUp(): void
    {
        $this->now = 1_000_000;
        $this->workDir = sys_get_temp_dir() . '/cast-sched-' . bin2hex(random_bytes(6));
        $this->jailRoot = sys_get_temp_dir() . '/cast-sched-jail-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0777, true);
        mkdir($this->jailRoot, 0777, true);
        $this->runs = new InMemoryRunRepository();
        $this->items = new InMemoryWorkItemRepository();
        $this->scheduler = new InMemoryScheduler();
        $this->factory = new WorkItemFactory();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workDir);
        $this->removeTree($this->jailRoot);
    }

    public function testArmsExactlyTheBoundedFanoutForACaptureRun(): void
    {
        $this->runAtCapture();
        for ($i = 0; $i < 5; ++$i) {
            $this->insert(sprintf('https://blog.example.test/page-%d/', $i));
        }

        $armed = $this->scheduler(2)->scheduleWorkers(self::RUN, $this->now);

        self::assertSame(2, $armed);
        self::assertSame(2, $this->scheduler->count());
        $hooks = array_map(static fn (array $event): string => $event['hook'], $this->scheduler->all());
        self::assertSame([CaptureWorkerScheduler::WORKER_HOOK, CaptureWorkerScheduler::WORKER_HOOK], $hooks);
    }

    public function testDuplicateSchedulingArmsNothingNew(): void
    {
        $this->runAtCapture();
        $this->insert('https://blog.example.test/a/');
        $coordinator = $this->scheduler(2);

        $first = $coordinator->scheduleWorkers(self::RUN, $this->now);
        $second = $coordinator->scheduleWorkers(self::RUN, $this->now + 60);
        $third = $coordinator->scheduleWorkers(self::RUN, $this->now + 120);

        self::assertSame(2, $first);
        self::assertSame(0, $second, 'an already-armed worker slot is not re-armed');
        self::assertSame(0, $third);
        self::assertSame(2, $this->scheduler->count(), 'the fanout stays bounded across repeated coordination');
    }

    public function testDoesNotArmWhenTheRunIsMissing(): void
    {
        self::assertSame(0, $this->scheduler(2)->scheduleWorkers('run-gone', $this->now));
        self::assertSame(0, $this->scheduler->count());
    }

    public function testDoesNotArmWhenTheRunIsTerminal(): void
    {
        $run = $this->runAtCapture();
        $run->complete(at: $this->now);
        $this->runs->save($run);

        self::assertSame(0, $this->scheduler(2)->scheduleWorkers(self::RUN, $this->now));
        self::assertSame(0, $this->scheduler->count());
    }

    public function testDoesNotArmWhenTheRunIsSuperseded(): void
    {
        $run = $this->runAtCapture();
        $run->supersede(at: $this->now);
        $this->runs->save($run);

        self::assertSame(0, $this->scheduler(2)->scheduleWorkers(self::RUN, $this->now));
        self::assertSame(0, $this->scheduler->count());
    }

    public function testDoesNotArmWhenTheRunIsNotAtCapture(): void
    {
        $run = $this->runAtCapture();
        $run->recordResumeCursor('rewrite|', at: $this->now);
        $this->runs->save($run);

        self::assertSame(0, $this->scheduler(2)->scheduleWorkers(self::RUN, $this->now));
        self::assertSame(0, $this->scheduler->count());
    }

    public function testDuplicateFanoutNeverCapturesAnItemTwice(): void
    {
        $this->runAtCapture();
        $this->insert('https://blog.example.test/a/');
        $coordinator = $this->scheduler(2);
        $coordinator->scheduleWorkers(self::RUN, $this->now);
        $coordinator->scheduleWorkers(self::RUN, $this->now);

        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], $this->html('a')),
        ]);
        $this->assertSame(1, $this->fireArmedWorkers($transport), 'exactly one of the two armed workers found work');
        self::assertSame(1, $this->items->countByStatus(self::RUN, WorkItemStatus::Done), 'one item, one capture, no duplicates');
        self::assertSame(1, count($transport->requested), 'the item was fetched exactly once');
    }

    public function testBoundedFanoutCapturesAtMostOneItemPerArmedWorker(): void
    {
        $this->runAtCapture();
        for ($i = 0; $i < 5; ++$i) {
            $this->insert(sprintf('https://blog.example.test/page-%d/', $i));
        }
        $coordinator = $this->scheduler(2);
        $coordinator->scheduleWorkers(self::RUN, $this->now);

        $transport = new FakeCaptureTransport(array_fill(0, 5, CaptureResponse::withString(200, [], $this->html('x'))));
        $this->assertSame(2, $this->fireArmedWorkers($transport), 'exactly the bounded fanout captured');
        self::assertSame(2, $this->items->countByStatus(self::RUN, WorkItemStatus::Done), 'one item each');
        self::assertSame(3, $this->items->countByStatus(self::RUN, WorkItemStatus::Queued), 'the rest stays queued for later workers');
        self::assertSame(2, count($transport->requested));
    }

    private function scheduler(int $maxWorkers): CaptureWorkerScheduler
    {
        return new CaptureWorkerScheduler($this->runs, $this->scheduler, $maxWorkers);
    }

    /**
     * Fires every armed worker event through a real one-item worker with a
     * unique per-slot token; the workers that find no work exit idle, the
     * exclusive claim having taken the rows.
     */
    private function fireArmedWorkers(FakeCaptureTransport $transport): int
    {
        $environment = new FakeCaptureEnvironment($transport, new JailedDiskAssetSource($this->jailRoot));
        $captured = 0;
        foreach ($this->scheduler->all() as $event) {
            /** @var array{0: string, 1: int} $args */
            $args = $event['args'];
            $worker = new CaptureWorker(
                $this->runs,
                $this->items,
                $environment,
                workerToken: sprintf('cast-capture-worker-%d', $args[1]),
            );
            $outcome = $worker->work($args[0]);
            if ($outcome->kind === CaptureWorkerOutcomeKind::Captured) {
                ++$captured;
            } elseif ($outcome->kind !== CaptureWorkerOutcomeKind::SkippedIdle) {
                self::fail('armed worker exited unexpectedly: ' . $outcome->kind->value);
            }
        }

        return $captured;
    }

    private function runAtCapture(): \LumeWeb\Cast\Export\ExportRun
    {
        $run = \LumeWeb\Cast\Export\ExportRun::create(self::RUN, new RunSettings(hostname: 'blog.example.test'), at: $this->now);
        $run->start(at: $this->now);
        $run->advanceStage(RunStage::Exporting, at: $this->now);
        $origin = Origin::fromUrl((new UrlCanonicalizer())->canonicalize(self::ORIGIN));
        $run->recordProbe(new ProbeResult($origin, self::ORIGIN, 2048, 5), at: $this->now);
        $run->recordSetup(new SetupResult($this->workDir), at: $this->now);
        $run->recordResumeCursor('capture|', at: $this->now);
        $this->runs->save($run);

        return $run;
    }

    private function insert(string $url): void
    {
        $this->items->insertCanonical(self::RUN, $this->factory->fromString($url));
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
