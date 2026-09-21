<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\PipelineStageKey;
use LumeWeb\Cast\Export\PipelineState;
use LumeWeb\Cast\Export\ProbeResult;
use LumeWeb\Cast\Export\RewriteStage;
use LumeWeb\Cast\Export\RewriteSummary;
use LumeWeb\Cast\Export\SetupResult;
use LumeWeb\Cast\Export\StageResult;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Export\WorkItemStatus;
use PHPUnit\Framework\TestCase;

/**
 * The rewrite stage: post-capture, over completed captured items. Claims
 * one rewritable (text-like) Done work item per bounded tick in priority
 * order, reads its captured body, runs the existing {@see RewriteService}
 * with an item-URL {@see RewriteContext} and a canonical
 * {@see WorkItemQueueCollector}, writes the rewritten body back, enqueues
 * in-origin discovered work items, passes binary/fixed items through
 * untouched, surfaces leftover-origin warnings, and reports done('') only at
 * the fixed point (no rewritable Done item remains).
 */
final class RewriteStageTest extends TestCase
{
    private const ORIGIN = 'https://example.test/';

    private InMemoryWorkItemRepository $repo;
    private PipelineState $state;
    private string $workDir;
    private WorkItemFactory $factory;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/cast-rewrite-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0777, true);
        $this->repo = new InMemoryWorkItemRepository();
        $this->state = $this->rewritableState();
        $this->factory = new WorkItemFactory();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workDir);
    }

    public function testPipelineStateDeclaresTheRewriteSlot(): void
    {
        $reflection = new \ReflectionClass(PipelineState::class);

        self::assertTrue($reflection->hasProperty('rewrite'));
        self::assertNull($reflection->getProperty('rewrite')->getDefaultValue());
    }

    public function testKeyIsRewrite(): void
    {
        $stage = $this->stage(new FakeRewriteEnvironment([]));

        self::assertSame(PipelineStageKey::Rewrite, $stage->key());
    }

    public function testRequiresSuccessfulProbeBeforeRewrite(): void
    {
        $stage = $this->stage(new FakeRewriteEnvironment([]), new PipelineState());

        $result = $stage->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('probe', strtolower($result->failure));
    }

    public function testRequiresSuccessfulSetupBeforeRewrite(): void
    {
        $state = $this->rewritableState();
        $state->setup = null;

        $result = $this->stage(new FakeRewriteEnvironment([]), $state)->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('setup', strtolower($result->failure));
    }

    public function testProcessesOneDoneRewritableItemPerTick(): void
    {
        $this->insertDone('https://example.test/a/');
        $this->insertDone('https://example.test/b/');
        $this->insertDone('https://example.test/c/');
        $env = new FakeRewriteEnvironment([
            'a/index.html' => '<html><body>a</body></html>',
            'b/index.html' => '<html><body>b</body></html>',
            'c/index.html' => '<html><body>c</body></html>',
        ]);

        $result = $this->stage($env)->execute('');

        self::assertFalse($result->done);
        self::assertSame(1, $result->progress);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Rewritten));
        self::assertSame(2, $this->repo->countByStatus(WorkItemStatus::Done));
        self::assertNull($this->state->rewrite);
    }

    public function testClaimsLowestNumericPriorityFirst(): void
    {
        $this->insertDone('https://example.test/high/', 10);
        $this->insertDone('https://example.test/low/', 1);
        $env = new FakeRewriteEnvironment([
            'low/index.html' => '<html><body>low</body></html>',
            'high/index.html' => '<html><body>high</body></html>',
        ]);

        $this->stage($env)->execute('');

        self::assertSame(['low/index.html'], array_keys($env->written));
    }

    public function testRewritesHtmlBodyAndWritesRewrittenContentBack(): void
    {
        $this->insertDone('https://example.test/about/');
        $env = new FakeRewriteEnvironment([
            'about/index.html' => '<html><head><title>About</title></head><body>'
                . '<img src="/wp-content/themes/x/logo.png"></body></html>',
        ]);

        $result = $this->drainAll($env);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Rewritten));
        self::assertArrayHasKey('about/index.html', $env->written);
        self::assertStringContainsString('./../wp-content/themes/x/logo.png', $env->written['about/index.html']);
    }

    public function testEnqueuesDiscoveredInOriginWorkItems(): void
    {
        $this->insertDone('https://example.test/about/');
        $env = new FakeRewriteEnvironment([
            'about/index.html' => '<html><body>'
                . '<img src="/wp-content/themes/x/logo.png"></body></html>',
        ]);

        $result = $this->drainAll($env);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        // The asset surfaced by rewriting joins the canonical queue at the
        // urgent rewrite-derived priority. Whether it is then drained is the
        // reconciliation pass's job: with no capture environment wired the
        // stage still collects (pages being dropped) and finishes, leaving the
        // collected queue for a run that wires the reconciliation.
        $discovered = $this->factory->fromString('https://example.test/wp-content/themes/x/logo.png');
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Queued));
        self::assertSame(1, $this->repo->priorityOf($discovered->urlHash()));
    }

    public function testBinaryAssetDoneItemPassesThroughUntouched(): void
    {
        $this->insertDone('https://example.test/wp-content/uploads/2024/photo.png');
        $env = new FakeRewriteEnvironment([]);

        $result = $this->drainAll($env);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(0, $this->repo->countByStatus(WorkItemStatus::Rewritten));
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Done), 'binary stays done');
        self::assertSame([], $env->written, 'no body is read or rewritten');
    }

    public function testLeftoverOriginWarningsAreSurfaced(): void
    {
        $this->insertDone('https://example.test/wp-content/themes/x/app.js');
        $env = new FakeRewriteEnvironment([
            'wp-content/themes/x/app.js' => "var a = \"ok\";\n// leftover: https://example.test/unrewritable\n",
        ]);

        $result = $this->stage($env)->execute('');

        self::assertFalse($result->done);
        self::assertNotEmpty($result->warnings, 'a leftover-origin finding must surface as a warning');
        self::assertStringContainsString('still appears in rewritten content', $result->warnings[0]);
    }

    public function testDoneOnlyAtFixedPoint(): void
    {
        $this->insertDone('https://example.test/a/');
        $this->insertDone('https://example.test/b/');
        $env = new FakeRewriteEnvironment([
            'a/index.html' => '<html><body>a</body></html>',
            'b/index.html' => '<html><body>b</body></html>',
        ]);

        $result = $this->drainAll($env);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame('', $result->cursor);
        self::assertSame(2, $this->repo->countByStatus(WorkItemStatus::Rewritten));
        self::assertSame(0, $this->repo->countByStatus(WorkItemStatus::Done));
    }

    public function testRewriteSummaryTalliesRewrittenAndPassedThrough(): void
    {
        $this->insertDone('https://example.test/about/');
        $this->insertDone('https://example.test/wp-content/uploads/2024/photo.png');
        $env = new FakeRewriteEnvironment([
            'about/index.html' => '<html><body>about</body></html>',
        ]);

        $result = $this->drainAll($env);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        $summary = $this->state->rewrite;
        self::assertInstanceOf(RewriteSummary::class, $summary);
        self::assertSame(1, $summary->rewritten);
        self::assertSame(1, $summary->passedThrough);
        // No asset was surfaced in the rewritten body, so the summary carries
        // zero collected assets — the honest second half of the run total.
        self::assertSame(0, $summary->assetsCollected);
    }

    public function testEmptyDoneQueueCompletesImmediately(): void
    {
        $result = $this->drainAll(new FakeRewriteEnvironment([]));

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        $summary = $this->state->rewrite;
        self::assertInstanceOf(RewriteSummary::class, $summary);
        self::assertSame(0, $summary->rewritten);
        self::assertSame(0, $summary->passedThrough);
    }

    public function testFailsTheTickWhenCapturedBodyIsMissing(): void
    {
        $this->insertDone('https://example.test/about/');
        $env = new FakeRewriteEnvironment([]);

        $result = $this->stage($env)->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('about/index.html', $result->failure);
        // The item was not consumed: a later tick (after the body exists) can
        // still rewrite it.
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Done));
    }

    private function insertDone(string $url, int $priority = 10): WorkItem
    {
        $item = $this->factory->fromString($url);
        $this->repo->insertCanonical($item, $priority);
        $this->repo->transition($item->urlHash(), WorkItemStatus::Done);

        return $item;
    }

    private function stage(FakeRewriteEnvironment $env, ?PipelineState $state = null): RewriteStage
    {
        return new RewriteStage($env, $state ?? $this->state, $this->repo);
    }

    private function drainAll(FakeRewriteEnvironment $env, int $maxTicks = 100): StageResult
    {
        $cursor = '';
        $result = StageResult::more('');
        for ($tick = 0; $tick < $maxTicks; ++$tick) {
            $result = $this->stage($env)->execute($cursor);
            $cursor = $result->cursor;
            if ($result->done || $result->failure !== null) {
                break;
            }
        }

        return $result;
    }

    private function rewritableState(): PipelineState
    {
        $state = new PipelineState();
        $origin = Origin::fromUrl((new UrlCanonicalizer())->canonicalize(self::ORIGIN));
        $state->probe = new ProbeResult($origin, self::ORIGIN, 2048, 5);
        $state->setup = new SetupResult($this->workDir);

        return $state;
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
