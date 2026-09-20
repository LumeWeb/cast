<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\RepositoryWorkItemStateProvider;
use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Export\WorkItemStatus;
use PHPUnit\Framework\TestCase;

final class WorkItemStateProviderTest extends TestCase
{
    public function testReportsPendingFromRepository(): void
    {
        $repo = new InMemoryWorkItemRepository();
        $factory = new WorkItemFactory();
        $provider = new RepositoryWorkItemStateProvider($repo);

        self::assertFalse($provider->hasPending());
        self::assertSame(0, $provider->countByStatus(WorkItemStatus::Queued));

        $repo->insertCanonical($factory->fromString('https://example.com/'));
        self::assertTrue($provider->hasPending());
        self::assertSame(1, $provider->countByStatus(WorkItemStatus::Queued));

        $repo->transition($factory->fromString('https://example.com/')->urlHash(), WorkItemStatus::Done);
        self::assertFalse($provider->hasPending());
        self::assertSame(1, $provider->countByStatus(WorkItemStatus::Done));
    }

    public function testTerminalCountsExposeDoneFailedSkipped(): void
    {
        $repo = new InMemoryWorkItemRepository();
        $factory = new WorkItemFactory();
        $provider = new RepositoryWorkItemStateProvider($repo);

        $items = [
            $factory->fromString('https://example.com/a'),
            $factory->fromString('https://example.com/b'),
            $factory->fromString('https://example.com/c'),
        ];
        $hashes = array_map(static fn ($item) => $item->urlHash(), $items);
        foreach ($items as $item) {
            $repo->insertCanonical($item);
        }

        $repo->transition($hashes[0], WorkItemStatus::Done);
        $repo->transition($hashes[1], WorkItemStatus::Failed);
        $repo->transition($hashes[2], WorkItemStatus::Skipped);

        self::assertFalse($provider->hasPending());
        self::assertSame(1, $provider->countByStatus(WorkItemStatus::Done));
        self::assertSame(1, $provider->countByStatus(WorkItemStatus::Failed));
        self::assertSame(1, $provider->countByStatus(WorkItemStatus::Skipped));
        self::assertSame(0, $provider->countByStatus(WorkItemStatus::Queued));
    }
}
