<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\ArtifactValidationMode;
use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\PackResult;
use LumeWeb\Cast\Export\PackStage;
use LumeWeb\Cast\Export\PackStatus;
use LumeWeb\Cast\Export\PipelineStageKey;
use LumeWeb\Cast\Export\PipelineState;
use LumeWeb\Cast\Export\ProbeResult;
use LumeWeb\Cast\Export\SetupResult;
use LumeWeb\Cast\Export\StageResult;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Export\WorkItemStatus;
use PHPUnit\Framework\TestCase;

/**
 * The pack stage: after rewrite, runs the pre-pack validation check over the
 * completed work tree (fixed point, root index, output-path integrity,
 * leftover origins / broken references), drives the existing {@see ZipPackager}
 * to produce the artifact ZIP + manifest, handles the empty artifact and fatal
 * packer failures, surfaces warnings, and records the final {@see PackResult}
 * into {@see PipelineState::$pack}. One bounded tick per run; done('') at the
 * pack fixed point.
 */
final class PackStageTest extends TestCase
{
    private const ORIGIN = 'https://example.test/';
    private const RUN_ID = 'run-123';

    private string $root;
    private string $workDir;
    private string $artifactDir;
    private InMemoryWorkItemRepository $repo;
    private PipelineState $state;
    private WorkItemFactory $factory;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cast-pack-stage-' . bin2hex(random_bytes(6));
        $this->workDir = $this->root . '/work';
        mkdir($this->workDir, 0777, true);
        $this->artifactDir = $this->root . '/exports';
        $this->repo = new InMemoryWorkItemRepository();
        $this->state = $this->packableState();
        $this->factory = new WorkItemFactory();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testPipelineStateDeclaresThePackSlot(): void
    {
        $reflection = new \ReflectionClass(PipelineState::class);

        self::assertTrue($reflection->hasProperty('pack'));
        self::assertNull($reflection->getProperty('pack')->getDefaultValue());
    }

    public function testKeyIsPack(): void
    {
        self::assertSame(PipelineStageKey::Pack, $this->stage()->key());
    }

    public function testRequiresSuccessfulProbeBeforePack(): void
    {
        $result = $this->stage(new PipelineState())->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('probe', strtolower($result->failure));
    }

    public function testRequiresSuccessfulSetupBeforePack(): void
    {
        $state = $this->packableState();
        $state->setup = null;

        $result = $this->stage($state)->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('setup', strtolower($result->failure));
    }

    public function testPendingItemsBlockPackingBeforeZipIsOpened(): void
    {
        $this->writeValidTree();
        $this->repo->insertCanonical($this->factory->fromString(self::ORIGIN . 'pending/'));
        $env = $this->environment();

        $result = $this->stage($this->state, $env, $this->repo)->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('fixed point', strtolower($result->failure));
        self::assertFileDoesNotExist($env->artifactPath(self::RUN_ID));
    }

    public function testDuplicateOutputPathsBlockPacking(): void
    {
        $this->writeValidTree();
        mkdir($this->workDir . '/a', 0777, true);
        file_put_contents($this->workDir . '/a/b.txt', 'one');
        // A literal backslash in a filename normalizes to the same path as a/b.txt.
        file_put_contents($this->workDir . '/a\\b.txt', 'two');
        $env = $this->environment();

        $result = $this->stage($this->state, $env, $this->repo)->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('duplicate', strtolower($result->failure));
        self::assertFileDoesNotExist($env->artifactPath(self::RUN_ID));
    }

    public function testStrictModeEscalatedLeftoverBlocksPacking(): void
    {
        file_put_contents($this->workDir . '/index.html', $this->pad(
            '<html><body><a href="https://example.test/wp-content/x.css">x</a></body></html>',
        ));
        $env = new FakePackEnvironment($this->artifactDir, ArtifactValidationMode::Strict);

        $result = $this->stage($this->state, $env, $this->repo)->execute('');

        self::assertNotNull($result->failure);
        self::assertFileDoesNotExist($env->artifactPath(self::RUN_ID));
    }

    public function testEmptyWorkDirectoryStillProducesInspectableArtifact(): void
    {
        $env = $this->environment();

        $result = $this->stage($this->state, $env, $this->repo)->execute('');

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame('', $result->cursor);

        $pack = $this->state->pack;
        self::assertInstanceOf(PackResult::class, $pack);
        self::assertSame(PackStatus::Completed, $pack->status);
        self::assertFileExists($pack->zipPath);
        self::assertSame(22, filesize($pack->zipPath));
        self::assertNotNull($pack->manifestPath);
        self::assertFileExists($pack->manifestPath);

        $manifest = json_decode((string) file_get_contents($pack->manifestPath), true);
        self::assertIsArray($manifest);
        self::assertTrue($manifest['empty_artifact']);
        self::assertSame(0, $manifest['packed_files']);
    }

    public function testPacksValidTreeAndRecordsPackResult(): void
    {
        $this->writeValidTree();
        $this->insertDone('https://example.test/');
        $this->insertDone('https://example.test/about/');
        $env = $this->environment();

        $result = $this->stage($this->state, $env, $this->repo)->execute('');

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame([], $result->warnings);

        $pack = $this->state->pack;
        self::assertInstanceOf(PackResult::class, $pack);
        self::assertSame(PackStatus::Completed, $pack->status);
        self::assertSame(2, $pack->filesAdded);
        self::assertSame($env->artifactPath(self::RUN_ID), $pack->zipPath);
        self::assertFileExists($pack->zipPath);
        self::assertNotNull($pack->manifestPath);
        self::assertFileExists($pack->manifestPath);

        $manifest = json_decode((string) file_get_contents($pack->manifestPath), true);
        self::assertIsArray($manifest);
        self::assertSame(self::RUN_ID, $manifest['run_id']);
        self::assertSame('https://example.test', $manifest['origin']);
    }

    public function testSurfacesLeftoverOriginWarningsAndCompletesWithWarnings(): void
    {
        file_put_contents($this->workDir . '/index.html', $this->pad(
            '<html><body><a href="https://example.test/wp-content/x.css">x</a></body></html>',
        ));
        $env = $this->environment();

        $result = $this->stage($this->state, $env, $this->repo)->execute('');

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('origin url is still present', strtolower($result->warnings[0]));

        $pack = $this->state->pack;
        self::assertInstanceOf(PackResult::class, $pack);
        self::assertSame(PackStatus::CompletedWithWarnings, $pack->status);
        self::assertFileExists($pack->zipPath);
    }

    public function testFatalPackFailureFailsTheStageAndRecordsFailure(): void
    {
        $this->writeValidTree();
        // Force the fatal in-jail artifact path so the ZipPackager refuses.
        $zipPath = $this->workDir . '/self.zip';
        $env = new FakePackEnvironment($this->artifactDir, ArtifactValidationMode::Warning, $zipPath);

        $result = $this->stage($this->state, $env, $this->repo)->execute('');

        self::assertNotNull($result->failure);
        self::assertFileDoesNotExist($zipPath);

        $pack = $this->state->pack;
        self::assertInstanceOf(PackResult::class, $pack);
        self::assertSame(PackStatus::Failed, $pack->status);
        self::assertNull($pack->manifestPath);
    }

    public function testCompletesInOneBoundedTick(): void
    {
        $this->writeValidTree();
        $this->insertDone('https://example.test/');
        $this->insertDone('https://example.test/about/');

        $result = $this->stage()->execute('');

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame('', $result->cursor);
    }

    private function stage(
        ?PipelineState $state = null,
        ?FakePackEnvironment $env = null,
        ?InMemoryWorkItemRepository $repo = null,
    ): PackStage {
        return new PackStage(
            $env ?? $this->environment(),
            $state ?? $this->state,
            $repo ?? $this->repo,
            self::RUN_ID,
        );
    }

    private function environment(): FakePackEnvironment
    {
        return new FakePackEnvironment($this->artifactDir);
    }

    private function packableState(): PipelineState
    {
        $state = new PipelineState();
        $origin = Origin::fromUrl((new UrlCanonicalizer())->canonicalize(self::ORIGIN));
        $state->probe = new ProbeResult($origin, self::ORIGIN, 2048, 5);
        $state->setup = new SetupResult($this->workDir);

        return $state;
    }

    private function writeValidTree(): void
    {
        mkdir($this->workDir . '/about', 0777, true);
        file_put_contents($this->workDir . '/index.html', $this->pad(
            '<html><body><a href="about/index.html">about</a></body></html>',
        ));
        file_put_contents($this->workDir . '/about/index.html', $this->pad('<html><body>about</body></html>'));
    }

    private function insertDone(string $url): WorkItem
    {
        $item = $this->factory->fromString($url);
        $this->repo->insertCanonical($item);
        $this->repo->transition($item->urlHash(), WorkItemStatus::Done);

        return $item;
    }

    private function pad(string $html): string
    {
        return $html . str_repeat(' ', 1500);
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
