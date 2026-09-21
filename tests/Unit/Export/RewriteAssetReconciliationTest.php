<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\ArtifactValidationMode;
use LumeWeb\Cast\Export\ArtifactValidator;
use LumeWeb\Cast\Export\CaptureResponse;
use LumeWeb\Cast\Export\DirectoryArtifactTree;
use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\JailedDiskAssetSource;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\PipelineState;
use LumeWeb\Cast\Export\ProbeResult;
use LumeWeb\Cast\Export\RepositoryWorkItemStateProvider;
use LumeWeb\Cast\Export\RewriteStage;
use LumeWeb\Cast\Export\SetupResult;
use LumeWeb\Cast\Export\StageResult;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\ValidationFinding;
use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Export\WorkItemStatus;
use PHPUnit\Framework\TestCase;

/**
 * The asset reconciliation: assembled assets/media are drained in a bounded
 * second pass inside the rewrite stage — after the rewritable-Done fixed
 * point — so the pipeline stays forward-only and the pack validation's
 * pendingCount() === 0 holds. The collector drops page URLs found in content
 * (discovery is the only page authority), captures every non-page candidate
 * through the same CaptureService machinery the capture stage uses, rewrites
 * text-like assets in-tick (absorbing secondary assets they reference), and
 * caps the cumulative collected-asset count with a surfaced warning.
 */
final class RewriteAssetReconciliationTest extends TestCase
{
    private const ORIGIN = 'https://example.test/';

    private const LOGO = 'wp-content/uploads/logo.png';

    private InMemoryWorkItemRepository $repo;
    private PipelineState $state;
    private string $workDir;
    private string $jailRoot;
    private WorkItemFactory $factory;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/cast-reconcile-' . bin2hex(random_bytes(6));
        $this->jailRoot = sys_get_temp_dir() . '/cast-reconcile-jail-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0777, true);
        mkdir($this->jailRoot, 0777, true);
        $this->repo = new InMemoryWorkItemRepository();
        $this->state = $this->state();
        $this->factory = new WorkItemFactory();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workDir);
        $this->removeTree($this->jailRoot);
    }

    public function testPageUrlInsideContentIsDroppedButAssetIsCollected(): void
    {
        $this->insertDonePage('https://example.test/home/', $this->pageBody(
            '<a href="/about/">About</a><img src="/wp-content/uploads/logo.png">',
        ));
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], 'logo-bytes'),
        ]);
        $env = new FilesystemRewriteEnvironment($this->workDir);

        $result = $this->stage($env, $transport)->execute('');

        self::assertFalse($result->done);
        self::assertNull($result->failure);
        // Grep-proof the policy end-to-end: the page link was never enqueued,
        // while the embedded asset joined the queue for reconciliation.
        self::assertNull($this->repo->priorityOf($this->hash('https://example.test/about/')), 'page link has no row at all');
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Queued));
        $logo = $this->factory->fromString('https://example.test/wp-content/uploads/logo.png');
        self::assertSame(1, $this->repo->priorityOf($logo->urlHash()));
        self::assertSame(self::LOGO, $logo->outputPath());
    }

    public function testReconciliationDrainsCollectedAssetsAndPassesThePackGate(): void
    {
        // Live failure shape fixture: 15 discovered items (14 pages + one
        // fixed favicon), capture leaves 12 done (11 pages + the favicon) and
        // 3 failed; rewriting the 11 done pages collects the embedded assets.
        $pages = [
            'https://example.test/',
            'https://example.test/a/',
            'https://example.test/b/',
            'https://example.test/c/',
            'https://example.test/d/',
            'https://example.test/e/',
            'https://example.test/f/',
            'https://example.test/g/',
            'https://example.test/h/',
            'https://example.test/i/',
            'https://example.test/j/',
        ];
        foreach ($pages as $index => $url) {
            $isRoot = $index === 0;
            $body = $isRoot
                ? $this->pageBody('<link rel="stylesheet" href="/wp-content/themes/x/style.css"><img src="/wp-content/uploads/logo.png">')
                : $this->pageBody('<img src="/wp-content/uploads/logo.png">');
            $this->insertDonePage($url, $body);
        }
        $this->insertDone('https://example.test/favicon.png');
        $this->writeWorkFile('favicon.png', 'favicon-bytes');
        $failed = ['https://example.test/k/', 'https://example.test/l/', 'https://example.test/m/'];
        foreach ($failed as $url) {
            $this->insert($url);
        }
        foreach ($failed as $url) {
            $this->repo->transition($this->factory->fromString($url)->urlHash(), WorkItemStatus::Failed);
        }

        // The reconciliation pass fetches the collected assets in claim order: the stylesheet
        // first (its rewrite discovers the hero image), then the logo, then
        // the hero image.
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], 'body { background: url("/wp-content/uploads/hero.png"); }'),
            CaptureResponse::withString(200, [], 'logo-bytes'),
            CaptureResponse::withString(200, [], 'hero-bytes'),
        ]);

        $result = $this->drainAll($this->stage(new FilesystemRewriteEnvironment($this->workDir), $transport));

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        // 12 rewritten = the 11 done pages + the stylesheet the reconciliation
        // captured and rewrote in-tick; the fixed assets (favicon + logo +
        // hero) pass through Done untouched and the 3 failed captures stay
        // terminal Failed — none of them pending.
        self::assertSame(12, $this->repo->countByStatus(WorkItemStatus::Rewritten));
        self::assertSame(3, $this->repo->countByStatus(WorkItemStatus::Done), 'favicon + logo + hero pass through');
        self::assertSame(3, $this->repo->countByStatus(WorkItemStatus::Failed));
        self::assertSame(0, $this->repo->pendingCount(), 'the pack gate must see zero pending work');

        $summary = $this->state->rewrite;
        self::assertNotNull($summary);
        self::assertSame(12, $summary->rewritten);
        self::assertSame(3, $summary->passedThrough);
        // The summary pins the cumulative collected count (logo + stylesheet +
        // hero) so the dashboard denominator can still count the reconciled
        // work once the rewrite cursor advances past the stage.
        self::assertSame(3, $summary->assetsCollected);

        // The pre-pack validation passes the pending-items check: no row
        // is queued or processing after the reconciliation drained.
        $report = (new ArtifactValidator(
            new DirectoryArtifactTree($this->workDir),
            new RepositoryWorkItemStateProvider($this->repo),
            Origin::fromUrl((new UrlCanonicalizer())->canonicalize(self::ORIGIN)),
            ArtifactValidationMode::Warning,
        ))->validate();

        $categories = array_map(
            static fn (ValidationFinding $finding): string => $finding->category,
            $report->findings,
        );
        self::assertNotContains('pending_items', $categories, 'the pack gate must pass with zero pending');
        self::assertSame(0, $report->counts['queued']);
        self::assertSame(0, $report->counts['processing']);
    }

    public function testSecondaryAssetDiscoveredWhileFetchingCssIsProcessedAcrossTicks(): void
    {
        // One page embeds a stylesheet; the stylesheet itself references an
        // image nobody else linked. The loop absorbs the secondary asset, and
        // a fresh stage instance resumes from the carried cursor.
        $this->insertDonePage('https://example.test/landing/', $this->pageBody(
            '<link rel="stylesheet" href="/wp-content/themes/x/style.css">',
        ));
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], 'body { color: red; background: url("/wp-content/uploads/hero.png"); }'),
            CaptureResponse::withString(200, [], 'hero-bytes'),
        ]);

        $env = new FilesystemRewriteEnvironment($this->workDir);
        $stageA = $this->stage($env, $transport);

        $first = $stageA->execute('');
        self::assertFalse($first->done);
        self::assertNotEmpty($first->cursor, 'the cursor carries the collected-asset count into the next tick');
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Queued), 'the stylesheet is collected by phase 1');

        // A fresh stage instance restarts from the persisted cursor: the reconciliation pass
        // claims the stylesheet, captures + rewrites it in one tick, and the
        // secondary hero image it discovered is captured next.
        $second = $this->stage($env, $transport)->execute($first->cursor);
        self::assertFalse($second->done);
        self::assertSame(2, $this->repo->countByStatus(WorkItemStatus::Rewritten), 'the page and the stylesheet were rewritten');

        $third = $this->stage($env, $transport)->execute($second->cursor);
        self::assertFalse($third->done);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Done), 'the hero image stays binary Done');

        $final = $this->stage($env, $transport)->execute($third->cursor);
        self::assertTrue($final->done);
        self::assertSame(0, $this->repo->pendingCount());
        self::assertSame(2, $this->repo->countByStatus(WorkItemStatus::Rewritten));
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Done));
    }

    public function testCollectedAssetCapRestrictsNewAssetsRecordsWarningAndDrainsBacklog(): void
    {
        $this->insertDonePage('https://example.test/home/', $this->pageBody(
            '<img src="/wp-content/uploads/a.png"><img src="/wp-content/uploads/b.png"><img src="/wp-content/uploads/c.png">',
        ));
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], 'a-bytes'),
            CaptureResponse::withString(200, [], 'b-bytes'),
        ]);
        $env = new FilesystemRewriteEnvironment($this->workDir);

        $first = $this->stage($env, $transport, maxAssets: 2)->execute('');

        // The crossing tick surface the cap warning once; c was refused ...
        self::assertFalse($first->done);
        self::assertNotEmpty($first->warnings, 'the cap crossing surfaces a warning');
        self::assertStringContainsString('cap of 2', $first->warnings[0]);
        self::assertNull($this->repo->priorityOf($this->hash('https://example.test/wp-content/uploads/c.png')), 'c was refused');
        self::assertSame(2, $this->repo->countByStatus(WorkItemStatus::Queued), 'a and b were collected');

        $final = $this->drainAll($this->stage($env, $transport, maxAssets: 2), seedCursor: $first->cursor);

        self::assertTrue($final->done);
        self::assertNull($final->failure);
        self::assertSame(0, $this->repo->pendingCount(), 'the collected backlog drains to the fixed point');
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Rewritten));
        self::assertSame(2, $this->repo->countByStatus(WorkItemStatus::Done));
    }

    public function testFailedAssetDoesNotBlockConvergence(): void
    {
        $this->insertDonePage('https://example.test/home/', $this->pageBody(
            '<img src="/wp-content/uploads/gone.png">',
        ));
        $transport = new FakeCaptureTransport([
            CaptureResponse::empty(404),
        ]);

        $result = $this->drainAll($this->stage(new FilesystemRewriteEnvironment($this->workDir), $transport));

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(0, $this->repo->pendingCount());
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Failed), 'a missing asset is terminal, not blocking');
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Rewritten));
    }

    private function insertDonePage(string $url, string $body): WorkItem
    {
        $item = $this->insertDone($url);
        $this->writeWorkFile($item->outputPath(), $body);

        return $item;
    }

    private function insertDone(string $url): WorkItem
    {
        $item = $this->insert($url);
        $this->repo->transition($item->urlHash(), WorkItemStatus::Done);

        return $item;
    }

    private function insert(string $url): WorkItem
    {
        $item = $this->factory->fromString($url);
        $this->repo->insertCanonical($item);

        return $item;
    }

    private function hash(string $url): string
    {
        return $this->factory->fromString($url)->urlHash();
    }

    private function writeWorkFile(string $relative, string $contents): void
    {
        $path = $this->workDir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function stage(
        FilesystemRewriteEnvironment $env,
        FakeCaptureTransport $transport,
        int $maxAssets = 200_000,
    ): RewriteStage {
        return new RewriteStage(
            $env,
            $this->state,
            $this->repo,
            new FakeCaptureEnvironment($transport, new JailedDiskAssetSource($this->jailRoot)),
            $maxAssets,
        );
    }

    private function drainAll(RewriteStage $stage, int $maxTicks = 200, string $seedCursor = ''): StageResult
    {
        $cursor = $seedCursor;
        $result = StageResult::more('');
        for ($tick = 0; $tick < $maxTicks; ++$tick) {
            $result = $stage->execute($cursor);
            $cursor = $result->cursor;
            if ($result->done || $result->failure !== null) {
                break;
            }
        }

        return $result;
    }

    private function state(): PipelineState
    {
        $state = new PipelineState();
        $origin = Origin::fromUrl((new UrlCanonicalizer())->canonicalize(self::ORIGIN));
        $state->probe = new ProbeResult($origin, self::ORIGIN, 2048, 5);
        $state->setup = new SetupResult($this->workDir);

        return $state;
    }

    private function pageBody(string $content): string
    {
        return '<html><head><title>Body</title></head><body>'
            . $content
            . str_repeat('<p>content</p>', 120)
            . '</body></html>';
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
