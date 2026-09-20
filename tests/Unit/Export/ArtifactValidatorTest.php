<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\ArtifactValidationMode;
use LumeWeb\Cast\Export\ArtifactValidator;
use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\PackStatus;
use LumeWeb\Cast\Export\RepositoryWorkItemStateProvider;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\ValidationSeverity;
use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Export\WorkItemStatus;
use PHPUnit\Framework\TestCase;

final class ArtifactValidatorTest extends TestCase
{
    private Origin $origin;

    protected function setUp(): void
    {
        $this->origin = Origin::fromUrl((new UrlCanonicalizer())->canonicalize('https://example.com/'));
    }

    public function testPendingItemsFailTheGate(): void
    {
        $tree = $this->cleanTree();
        $state = $this->stateWith(WorkItemStatus::Queued);

        $report = (new ArtifactValidator($tree, $state, $this->origin))->validate();

        self::assertSame(PackStatus::Failed, $report->status);
        self::assertSame('pending_items', $report->errors()[0]->category);
        self::assertSame(ValidationSeverity::Hard, $report->errors()[0]->severity);
    }

    public function testMissingRootIndexFails(): void
    {
        $tree = new ArrayArtifactTree(['about/index.html' => $this->pad('<html><body>about</body></html>')]);
        $state = new RepositoryWorkItemStateProvider(new InMemoryWorkItemRepository());

        $report = (new ArtifactValidator($tree, $state, $this->origin))->validate();

        self::assertSame(PackStatus::Failed, $report->status);
        self::assertSame('missing_root_index', $report->errors()[0]->category);
    }

    public function testGhostRootIndexFails(): void
    {
        $tree = new ArrayArtifactTree(['index.html' => '<html>tiny</html>']);
        $state = new RepositoryWorkItemStateProvider(new InMemoryWorkItemRepository());

        $report = (new ArtifactValidator($tree, $state, $this->origin))->validate();

        self::assertSame(PackStatus::Failed, $report->status);
        self::assertSame('ghost_root_index', $report->errors()[0]->category);
    }

    public function testUnsafeOutputPathFails(): void
    {
        $tree = new ArrayArtifactTree([
            'index.html' => $this->pad('<html><body>ok</body></html>'),
            '../evil.txt' => 'escape',
        ]);
        $state = new RepositoryWorkItemStateProvider(new InMemoryWorkItemRepository());

        $report = (new ArtifactValidator($tree, $state, $this->origin))->validate();

        self::assertSame(PackStatus::Failed, $report->status);
        self::assertSame('unsafe_output_path', $report->errors()[0]->category);
        self::assertSame('../evil.txt', $report->errors()[0]->path);
    }

    public function testDuplicateOutputPathsFail(): void
    {
        $tree = new ArrayArtifactTree([
            'index.html' => $this->pad('<html><body>ok</body></html>'),
            'css/style.css' => 'body {}',
            'css//style.css' => 'body {}',
        ]);
        $state = new RepositoryWorkItemStateProvider(new InMemoryWorkItemRepository());

        $report = (new ArtifactValidator($tree, $state, $this->origin))->validate();

        self::assertSame(PackStatus::Failed, $report->status);
        self::assertSame('duplicate_output_path', $report->errors()[0]->category);
        self::assertSame('css/style.css', $report->errors()[0]->path);
    }

    public function testLeftoverOriginCompletesWithWarnings(): void
    {
        $html = $this->pad('<html><body><a href="https://example.com/wp-content/x.css">x</a></body></html>');
        $tree = new ArrayArtifactTree([
            'index.html' => $html,
            'wp-content/x.css' => 'body {}',
        ]);
        $state = new RepositoryWorkItemStateProvider(new InMemoryWorkItemRepository());

        $report = (new ArtifactValidator($tree, $state, $this->origin))->validate();

        self::assertSame(PackStatus::CompletedWithWarnings, $report->status);
        self::assertSame('leftover_origin', $report->warnings()[0]->category);
        self::assertCount(0, $report->errors());
    }

    public function testStrictModeEscalatesLeftoverOriginToFailure(): void
    {
        $html = $this->pad('<html><body><a href="https://example.com/x">x</a></body></html>');
        $tree = new ArrayArtifactTree(['index.html' => $html]);
        $state = new RepositoryWorkItemStateProvider(new InMemoryWorkItemRepository());

        $report = (new ArtifactValidator($tree, $state, $this->origin, ArtifactValidationMode::Strict))->validate();

        self::assertSame(PackStatus::Failed, $report->status);
        self::assertSame('leftover_origin', $report->errors()[0]->category);
        self::assertSame(ValidationSeverity::Hard, $report->errors()[0]->severity);
    }

    public function testBrokenLocalReferenceCompletesWithWarnings(): void
    {
        $html = $this->pad('<html><body><a href="missing/index.html">x</a></body></html>');
        $tree = new ArrayArtifactTree(['index.html' => $html]);
        $state = new RepositoryWorkItemStateProvider(new InMemoryWorkItemRepository());

        $report = (new ArtifactValidator($tree, $state, $this->origin))->validate();

        self::assertSame(PackStatus::CompletedWithWarnings, $report->status);
        self::assertSame('broken_local_reference', $report->warnings()[0]->category);
    }

    public function testCleanTreeCompletes(): void
    {
        $tree = $this->cleanTree();
        $state = new RepositoryWorkItemStateProvider(new InMemoryWorkItemRepository());

        $report = (new ArtifactValidator($tree, $state, $this->origin))->validate();

        self::assertSame(PackStatus::Completed, $report->status);
        self::assertSame([], $report->findings);
    }

    public function testReportCarriesWorkItemAndFileCounts(): void
    {
        $tree = $this->cleanTree();
        $state = $this->stateWith(WorkItemStatus::Done);

        $report = (new ArtifactValidator($tree, $state, $this->origin))->validate();

        self::assertSame(1, $report->counts['done']);
        self::assertSame(0, $report->counts['queued']);
        self::assertSame(0, $report->counts['failed']);
        self::assertSame(2, $report->counts['files']);
    }

    private function cleanTree(): ArrayArtifactTree
    {
        return new ArrayArtifactTree([
            'index.html' => $this->pad('<html><body><a href="about/index.html">about</a></body></html>'),
            'about/index.html' => $this->pad('<html><body>about</body></html>'),
        ]);
    }

    /**
     * @param list<WorkItemStatus>|WorkItemStatus $status
     */
    private function stateWith($status): RepositoryWorkItemStateProvider
    {
        $statuses = is_array($status) ? $status : [$status];

        $repo = new InMemoryWorkItemRepository();
        $factory = new WorkItemFactory();
        foreach ($statuses as $i => $rowStatus) {
            $item = $factory->fromString('https://example.com/t' . $i);
            $repo->insertCanonical($item);
            $repo->transition($item->urlHash(), $rowStatus);
        }

        return new RepositoryWorkItemStateProvider($repo);
    }

    private function pad(string $html): string
    {
        return $html . str_repeat(' ', 1500);
    }
}
