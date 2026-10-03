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
    private const RUN = 'run-1';
    public function testReportsPendingFromRepository(): void
    {
        $repo = new InMemoryWorkItemRepository();
        $factory = new WorkItemFactory();
        $provider = new RepositoryWorkItemStateProvider($repo, fn (): string => self::RUN);

        self::assertFalse($provider->hasPending(self::RUN));
        self::assertSame(0, $provider->countByStatus(self::RUN, WorkItemStatus::Queued));

        $repo->insertCanonical(self::RUN, $factory->fromString('https://example.com/'));
        self::assertTrue($provider->hasPending(self::RUN));
        self::assertSame(1, $provider->countByStatus(self::RUN, WorkItemStatus::Queued));

        $repo->transition(self::RUN, $factory->fromString('https://example.com/')->urlHash(), WorkItemStatus::Done);
        self::assertFalse($provider->hasPending(self::RUN));
        self::assertSame(1, $provider->countByStatus(self::RUN, WorkItemStatus::Done));
    }

    public function testTerminalCountsExposeDoneFailedSkipped(): void
    {
        $repo = new InMemoryWorkItemRepository();
        $factory = new WorkItemFactory();
        $provider = new RepositoryWorkItemStateProvider($repo, fn (): string => self::RUN);

        $items = [
            $factory->fromString('https://example.com/a'),
            $factory->fromString('https://example.com/b'),
            $factory->fromString('https://example.com/c'),
        ];
        $hashes = array_map(static fn ($item) => $item->urlHash(), $items);
        foreach ($items as $item) {
            $repo->insertCanonical(self::RUN, $item);
        }

        $repo->transition(self::RUN, $hashes[0], WorkItemStatus::Done);
        $repo->transition(self::RUN, $hashes[1], WorkItemStatus::Failed);
        $repo->transition(self::RUN, $hashes[2], WorkItemStatus::Skipped);

        self::assertFalse($provider->hasPending(self::RUN));
        self::assertSame(1, $provider->countByStatus(self::RUN, WorkItemStatus::Done));
        self::assertSame(1, $provider->countByStatus(self::RUN, WorkItemStatus::Failed));
        self::assertSame(1, $provider->countByStatus(self::RUN, WorkItemStatus::Skipped));
        self::assertSame(0, $provider->countByStatus(self::RUN, WorkItemStatus::Queued));
    }
}
