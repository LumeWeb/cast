<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export\Rewrite;

use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\Rewrite\WorkItemQueueCollector;
use LumeWeb\Cast\Export\Url;
use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Export\WorkItemKind;
use LumeWeb\Cast\Export\WorkItemStatus;
use PHPUnit\Framework\TestCase;

final class WorkItemQueueCollectorTest extends TestCase
{
    private InMemoryWorkItemRepository $repository;
    private WorkItemQueueCollector $collector;
    private WorkItemFactory $factory;

    protected function setUp(): void
    {
        $this->repository = new InMemoryWorkItemRepository();
        $this->collector = new WorkItemQueueCollector($this->repository);
        $this->factory = new WorkItemFactory();
    }

    public function testQueueInsertsCanonically(): void
    {
        $item = $this->factory->fromString('https://example.com/fonts/a.woff2');

        $this->collector->queue($item);

        self::assertSame(1, $this->repository->countByStatus(WorkItemStatus::Queued));
        self::assertSame(1, $this->repository->pendingCount());
    }

    public function testQueueDeduplicatesSameCanonicalItem(): void
    {
        $item = $this->factory->fromString('https://example.com/a.jpg?ver=1');

        $this->collector->queue($item);
        $this->collector->queue($this->factory->fromString('https://example.com/a.jpg?ver=2'));

        self::assertSame(1, $this->repository->pendingCount());
    }

    public function testQueuePreservesOutputPath(): void
    {
        $item = $this->factory->fromString('https://example.com/wp-content/themes/x/style.css');

        $this->collector->queue($item);

        self::assertSame('wp-content/themes/x/style.css', $item->outputPath());
        $this->collector->queue($item);
    }

    public function testQueueHonoursConfiguredPriority(): void
    {
        $item = $this->factory->fromString('https://example.com/wp-content/themes/x/logo.png');
        $collector = new WorkItemQueueCollector($this->repository, 1);

        $collector->queue($item);

        self::assertSame(
            1,
            $this->repository->priorityOf($item->urlHash()),
            'the queue collector inserts with the configured urgent priority',
        );
    }

    public function testPageKindUrlIsDroppedAndNeverEnqueued(): void
    {
        // A page URL surfaced inside content must never be enqueued: the
        // discover/DB queue is the only authority for page URLs, so content
        // links are never fetched or crawled (grep-proof policy check).
        $item = $this->factory->fromString('https://example.com/about/');
        self::assertSame(WorkItemKind::Page, $item->kind());

        $this->collector->queue($item);

        self::assertSame(0, $this->repository->countByStatus(WorkItemStatus::Queued));
        self::assertSame(0, $this->repository->pendingCount());
        self::assertNull($this->repository->priorityOf($item->urlHash()), 'a dropped page has no row at all');
        self::assertSame(0, $this->collector->collectedCount());
    }

    public function testAssetKindUrlIsCollectedIntoTheQueue(): void
    {
        $item = $this->factory->fromString('https://example.com/wp-content/themes/x/logo.png');
        self::assertSame(WorkItemKind::Asset, $item->kind());

        $this->collector->queue($item);

        self::assertSame(1, $this->repository->countByStatus(WorkItemStatus::Queued));
        self::assertSame(1, $this->collector->collectedCount());
    }

    public function testCapRefusesNewAssetsAndRaisesCapHit(): void
    {
        $collector = new WorkItemQueueCollector($this->repository, 1, maxAssets: 2);
        $collector->queue($this->factory->fromString('https://example.com/wp-content/a.png'));
        $collector->queue($this->factory->fromString('https://example.com/wp-content/b.png'));
        $collector->queue($this->factory->fromString('https://example.com/wp-content/c.png'));

        self::assertSame(2, $this->repository->countByStatus(WorkItemStatus::Queued));
        self::assertSame(2, $collector->collectedCount());
        self::assertTrue($collector->capHit());
    }

    public function testCapDoesNotWarnOnDuplicateOfAnAlreadyCollectedAsset(): void
    {
        $collector = new WorkItemQueueCollector($this->repository, 1, maxAssets: 1);
        $ref = $this->factory->fromString('https://example.com/wp-content/a.png');
        $collector->queue($ref);
        $collector->queue($ref);

        self::assertSame(1, $this->repository->countByStatus(WorkItemStatus::Queued));
        self::assertFalse($collector->capHit(), 'a duplicate reference is not a refused new collection');
    }

    public function testGlobAssetReferenceIsNotEnqueued(): void
    {
        // A stylesheet/script can surface a glob like `/wp-content/themes/*.css`
        // where the page-kind check does not apply, so the collector must drop
        // it itself — a pattern never names one real resource. The WorkItem is
        // constructed directly (bypassing the normalizer's check) to prove this
        // entry point guards on its own.
        $item = new WorkItem(
            new Url('https', 'example.com', null, '/wp-content/themes/style-*.css', ''),
            WorkItemKind::Asset,
            'https://example.com/wp-content/themes/style-*.css',
            md5('https://example.com/wp-content/themes/style-*.css'),
            'wp-content/themes/style-*.css',
        );

        $this->collector->queue($item);

        self::assertSame(0, $this->repository->countByStatus(WorkItemStatus::Queued));
        self::assertNull($this->repository->priorityOf($item->urlHash()), 'a glob asset has no row at all');
        self::assertSame(0, $this->collector->collectedCount());

        // A percent-encoded `%2A` is one real escape, not a glob: it stays
        // collectable so legitimately-encoded assets are not lost.
        $encoded = $this->factory->fromString('https://example.com/wp-content/%2A-logo.png');
        $this->collector->queue($encoded);
        self::assertSame(1, $this->repository->countByStatus(WorkItemStatus::Queued));
    }
}
