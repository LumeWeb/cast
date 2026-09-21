<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\CaptureResponse;
use LumeWeb\Cast\Export\CaptureStage;
use LumeWeb\Cast\Export\CaptureSummary;
use LumeWeb\Cast\Export\JailedDiskAssetSource;
use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\PipelineStageKey;
use LumeWeb\Cast\Export\PipelineState;
use LumeWeb\Cast\Export\ProbeResult;
use LumeWeb\Cast\Export\RetryPolicy;
use LumeWeb\Cast\Export\SetupResult;
use LumeWeb\Cast\Export\StageResult;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Export\WorkItemStatus;
use PHPUnit\Framework\TestCase;

/**
 * The capture stage: claims queued work items one bounded unit per tick in
 * priority order, applies {@see CaptureService} outcomes to work-item states
 * (done/failed/skipped plus queue-level retry scheduling for residual
 * retryable outcomes), and reports done('') only once the queue has reached
 * its fixed point (pendingCount() == 0).
 */
final class CaptureStageTest extends TestCase
{
    private const ORIGIN = 'https://example.test/';

    private int $now;
    private InMemoryWorkItemRepository $repo;
    private PipelineState $state;
    private string $workDir;
    private string $jailRoot;
    private WorkItemFactory $factory;

    protected function setUp(): void
    {
        $this->now = 1_000_000;
        $this->workDir = sys_get_temp_dir() . '/cast-stage-' . bin2hex(random_bytes(6));
        $this->jailRoot = sys_get_temp_dir() . '/cast-jail-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0777, true);
        mkdir($this->jailRoot, 0777, true);
        $this->repo = new InMemoryWorkItemRepository(clock: function (): int {
            return $this->now;
        });
        $this->state = $this->capturableState();
        $this->factory = new WorkItemFactory();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workDir);
        $this->removeTree($this->jailRoot);
    }

    public function testPipelineStateDeclaresTheCaptureSlot(): void
    {
        $reflection = new \ReflectionClass(PipelineState::class);

        self::assertTrue($reflection->hasProperty('capture'));
        self::assertNull($reflection->getProperty('capture')->getDefaultValue());
    }

    public function testKeyIsCapture(): void
    {
        $stage = $this->stage($this->environment(new FakeCaptureTransport([])));

        self::assertSame(PipelineStageKey::Capture, $stage->key());
    }

    public function testRequiresSuccessfulProbeBeforeCapture(): void
    {
        $stage = $this->stage($this->environment(new FakeCaptureTransport([])), new PipelineState());

        $result = $stage->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('probe', strtolower($result->failure));
        self::assertSame(0, $this->repo->countByStatus(WorkItemStatus::Processing));
    }

    public function testRequiresSuccessfulSetupBeforeCapture(): void
    {
        $state = $this->capturableState();
        $state->setup = null;
        $this->insert('https://example.test/');

        $result = $this->stage($this->environment(new FakeCaptureTransport([])), $state)->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('setup', strtolower($result->failure));
        self::assertSame(0, $this->repo->countByStatus(WorkItemStatus::Processing));
    }

    public function testClaimsExactlyOneQueuedItemPerTick(): void
    {
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], $this->html('a')),
            CaptureResponse::withString(200, [], $this->html('b')),
            CaptureResponse::withString(200, [], $this->html('c')),
        ]);
        $this->insert('https://example.test/a/');
        $this->insert('https://example.test/b/');
        $this->insert('https://example.test/c/');

        $result = $this->stage($this->environment($transport))->execute('');

        self::assertFalse($result->done);
        self::assertSame(1, $result->progress);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Done));
        self::assertSame(2, $this->repo->countByStatus(WorkItemStatus::Queued));
        self::assertNull($this->state->capture);
    }

    public function testClaimsLowestNumericPriorityFirst(): void
    {
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], $this->html('low')),
            CaptureResponse::withString(200, [], $this->html('high')),
        ]);
        $this->repo->insertCanonical($this->factory->fromString('https://example.test/high/'), 10);
        $this->repo->insertCanonical($this->factory->fromString('https://example.test/low/'), 1);

        $result = $this->stage($this->environment($transport))->execute('');

        self::assertFalse($result->done);
        self::assertSame(['https://example.test/low/'], $transport->requested);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Done));
    }

    public function testFetchedPageIsMarkedDoneAndWritten(): void
    {
        $this->insert('https://example.test/about/');
        $env = $this->environment(new FakeCaptureTransport([
            CaptureResponse::withString(200, [], $this->html('about page')),
        ]));

        $result = $this->drainAll($env);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Done));
        self::assertSame($this->html('about page'), $this->readOutput('about/index.html'));
    }

    public function testLocalAssetIsCopiedWithoutAnyHttpRequest(): void
    {
        $this->writeJailFile('wp-content/themes/x/style.css', 'BODY { color: red; }');
        $transport = new FakeCaptureTransport([]);
        $this->insert('https://example.test/wp-content/themes/x/style.css');

        $result = $this->drainAll($this->environment($transport));

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Done));
        self::assertSame([], $transport->requested);
        self::assertSame('BODY { color: red; }', $this->readOutput('wp-content/themes/x/style.css'));
    }

    public function testRandom404MarksItemFailedWithoutRetry(): void
    {
        $this->insert('https://example.test/gone/');
        $env = $this->environment(new FakeCaptureTransport([CaptureResponse::empty(404)]));

        $result = $this->drainAll($env);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Failed));
        self::assertSame(1, $this->repo->attemptCountOf($this->hash('https://example.test/gone/')));
    }

    public function test403MarksItemFailed(): void
    {
        $this->insert('https://example.test/secret/');
        $env = $this->environment(new FakeCaptureTransport([
            CaptureResponse::withString(403, [], 'forbidden'),
        ]));

        $result = $this->drainAll($env);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Failed));
    }

    public function testGhostGuardMarksItemFailed(): void
    {
        $this->insert('https://example.test/tiny/');
        $env = $this->environment(new FakeCaptureTransport([
            CaptureResponse::withString(200, [], '<html><body>tiny</body></html>'),
        ]));

        $result = $this->drainAll($env);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Failed));
    }

    public function testOffOriginRedirectMarksItemSkipped(): void
    {
        $this->insert('https://example.test/away/');
        $env = $this->environment(new FakeCaptureTransport([
            CaptureResponse::withString(301, ['location' => 'https://evil.example.net/x'], ''),
        ]));

        $result = $this->drainAll($env);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Skipped));
    }

    public function testCanonicalTwinRedirectMarksItemSkipped(): void
    {
        $this->insert('https://example.test/about');
        $env = $this->environment(new FakeCaptureTransport([
            CaptureResponse::withString(301, ['location' => 'https://example.test/about/'], ''),
        ]));

        $result = $this->drainAll($env);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Skipped));
        self::assertFalse($this->outputExists('about/index.html'), 'a twin writes no stub next to the canonical');
    }

    public function testOnOriginPageRedirectMarksDoneAndWritesStub(): void
    {
        $this->insert('https://example.test/old/');
        $env = $this->environment(new FakeCaptureTransport([
            CaptureResponse::withString(301, ['location' => 'https://example.test/new/'], ''),
        ]));

        $result = $this->drainAll($env);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Done));
        self::assertTrue($this->outputExists('old/index.html'));
    }

    public function testRetryableOutcomeExhaustsSharedBudgetInOneClaim(): void
    {
        $this->insert('https://example.test/');
        // Three empty/200 responses consume the whole three-attempt budget
        // inside the single capture() run, so the applier marks the row
        // Failed instead of scheduling a further queue-level retry.
        $env = $this->environment(new FakeCaptureTransport(array_fill(0, 3, CaptureResponse::empty(200))));
        $hash = $this->hash('https://example.test/');

        $first = $this->stage($env)->execute('');

        self::assertFalse($first->done);
        self::assertSame(1, $first->progress);
        self::assertSame(1, $this->repo->attemptCountOf($hash), 'one queue claim happened');
        self::assertSame(0, $this->repo->countByStatus(WorkItemStatus::Done));
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Failed), 'the shared budget is exhausted');
        self::assertSame(0, $this->repo->countByStatus(WorkItemStatus::Queued), 'no queue-level retry is scheduled');
        self::assertSame(0, $this->repo->pendingCount());

        // The queue fixed point is reached on the next tick.
        $second = $this->stage($env)->execute('');

        self::assertTrue($second->done);
        self::assertSame('', $second->cursor);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Failed));
    }

    public function testRetryableOutcomeIsFetchedAtMostThreeTimes(): void
    {
        $this->insert('https://example.test/');
        // Even when the transport would keep failing forever, the combined
        // budget (inner attempts + prior claims) caps total fetches at
        // MAX_ATTEMPTS instead of the old double-counted ~9.
        $transport = new FakeCaptureTransport(array_fill(0, 9, CaptureResponse::empty(200)));
        $env = $this->environment($transport);

        $result = $this->drainAll($env);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Failed));
        self::assertSame(0, $this->repo->pendingCount());
        self::assertCount(
            RetryPolicy::MAX_ATTEMPTS,
            $transport->requested,
            'a persistently retryable item is never fetched more than MAX_ATTEMPTS times',
        );
    }

    public function testDoneOnlyAtQueueFixedPoint(): void
    {
        $this->insert('https://example.test/a/');
        $this->insert('https://example.test/b/');
        $env = $this->environment(new FakeCaptureTransport([
            CaptureResponse::withString(200, [], $this->html('a')),
            CaptureResponse::withString(200, [], $this->html('b')),
        ]));

        $result = $this->drainAll($env);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame('', $result->cursor);
        self::assertSame(2, $this->repo->countByStatus(WorkItemStatus::Done));
        self::assertSame(0, $this->repo->pendingCount());
    }

    public function testCaptureSummaryTalliesFinalStatuses(): void
    {
        $this->insert('https://example.test/ok/');
        $this->insert('https://example.test/gone/');
        $env = $this->environment(new FakeCaptureTransport([
            CaptureResponse::withString(200, [], $this->html('ok')),
            CaptureResponse::empty(404),
        ]));

        $result = $this->drainAll($env);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        $summary = $this->state->capture;
        self::assertInstanceOf(CaptureSummary::class, $summary);
        self::assertSame(1, $summary->done);
        self::assertSame(1, $summary->failed);
        self::assertSame(0, $summary->skipped);
    }

    public function testEmptyQueueCompletesImmediately(): void
    {
        $result = $this->drainAll($this->environment(new FakeCaptureTransport([])));

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        $summary = $this->state->capture;
        self::assertInstanceOf(CaptureSummary::class, $summary);
        self::assertSame(0, $summary->done);
        self::assertSame(0, $summary->failed);
        self::assertSame(0, $summary->skipped);
    }

    private function insert(string $url): void
    {
        $this->repo->insertCanonical($this->factory->fromString($url));
    }

    private function hash(string $url): string
    {
        return md5($this->factory->fromString($url)->identity());
    }

    private function environment(FakeCaptureTransport $transport): FakeCaptureEnvironment
    {
        return new FakeCaptureEnvironment($transport, new JailedDiskAssetSource($this->jailRoot));
    }

    private function stage(FakeCaptureEnvironment $env, ?PipelineState $state = null): CaptureStage
    {
        return new CaptureStage($env, $state ?? $this->state, $this->repo);
    }

    private function drainAll(FakeCaptureEnvironment $env, int $maxTicks = 100): StageResult
    {
        $cursor = '';
        $result = StageResult::more('');
        for ($tick = 0; $tick < $maxTicks; ++$tick) {
            $result = $this->stage($env)->execute($cursor);
            $cursor = $result->cursor;
            if ($result->done || $result->failure !== null) {
                break;
            }
            // Let any scheduled retry become due before the next tick.
            $this->now += 121;
        }

        return $result;
    }

    private function capturableState(): PipelineState
    {
        $state = new PipelineState();
        $origin = Origin::fromUrl((new UrlCanonicalizer())->canonicalize(self::ORIGIN));
        $state->probe = new ProbeResult($origin, self::ORIGIN, 2048, 5);
        $state->setup = new SetupResult($this->workDir);

        return $state;
    }

    private function html(string $label): string
    {
        return '<html><head><title>' . $label . '</title></head><body>' . str_repeat('<p>content</p>', 120) . '</body></html>';
    }

    private function writeJailFile(string $relative, string $contents): void
    {
        $path = $this->jailRoot . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function outputExists(string $relative): bool
    {
        return is_file($this->workDir . '/' . $relative);
    }

    private function readOutput(string $relative): string
    {
        $contents = file_get_contents($this->workDir . '/' . $relative);
        self::assertIsString($contents, 'expected output file ' . $relative);

        return $contents;
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
