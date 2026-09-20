<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\InMemoryRunRepository;
use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Export\RunStage;
use LumeWeb\Cast\Export\RunStatus;
use PHPUnit\Framework\TestCase;

final class RunRepositoryTest extends TestCase
{
    private InMemoryRunRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryRunRepository();
    }

    private function makeRun(string $id, int $at): ExportRun
    {
        return ExportRun::create($id, new RunSettings(hostname: 'blog.example.test'), at: $at);
    }

    public function testSaveAndFindReturnTheSameRun(): void
    {
        $run = $this->makeRun('run-1', 1000);
        $run->start(at: 1001);

        $this->repository->save($run);

        $found = $this->repository->find('run-1');
        self::assertNotNull($found);
        self::assertSame('run-1', $found->runId);
        self::assertSame(RunStatus::Running, $found->status);
        self::assertSame(1001, $found->updatedAt);
    }

    public function testFindUnknownRunIdReturnsNull(): void
    {
        self::assertNull($this->repository->find('missing'));
    }

    public function testSaveUpsertsByRunId(): void
    {
        $first = $this->makeRun('run-1', 1000);
        $first->start(at: 1001);
        $this->repository->save($first);

        $second = ExportRun::fromArray($first->toArray());
        $second->complete(at: 1005);
        $this->repository->save($second);

        $found = $this->repository->find('run-1');
        self::assertNotNull($found);
        self::assertSame(RunStatus::Completed, $found->status);
        self::assertSame(1005, $found->updatedAt);
        self::assertCount(1, $this->repository->list());
    }

    public function testDeleteRemovesAPersistedRun(): void
    {
        $this->repository->save($this->makeRun('run-1', 1000));
        $this->repository->save($this->makeRun('run-2', 2000));

        $this->repository->delete('run-1');

        self::assertNull($this->repository->find('run-1'));
        self::assertNotNull($this->repository->find('run-2'));
    }

    public function testLatestReturnsTheMostRecentlyUpdatedRun(): void
    {
        $old = $this->makeRun('old', 1000);
        $old->start(at: 1001);
        $new = $this->makeRun('new', 2000);
        $this->repository->save($new);
        $this->repository->save($old);

        self::assertSame('new', $this->repository->latest()?->runId);
    }

    public function testLatestPrefersTheMostRecentlySavedWhenTimesTie(): void
    {
        $a = $this->makeRun('a', 1000);
        $b = $this->makeRun('b', 1000);
        $this->repository->save($a);
        $this->repository->save($b);

        self::assertSame('b', $this->repository->latest()?->runId);
    }

    public function testLatestIsNullWhenEmpty(): void
    {
        self::assertNull($this->repository->latest());
    }

    public function testListReturnsEverySavedRun(): void
    {
        $this->repository->save($this->makeRun('a', 1000));
        $this->repository->save($this->makeRun('b', 2000));
        $this->repository->save($this->makeRun('c', 3000));

        self::assertCount(3, $this->repository->list());
    }

    public function testStoredRunRetainsStageAndPublishMetadata(): void
    {
        $run = $this->makeRun('run-1', 1000);
        $run->start(at: 1001);
        $run->advanceStage(RunStage::Exporting, at: 1002);
        $run->advanceStage(RunStage::Uploading, at: 1003);
        $run->recordUploadIdentifier('tus-1', at: 1004);
        $run->recordPublishIdentifiers('cid', 'website', 'ipns', at: 1005);

        $this->repository->save($run);

        $found = $this->repository->find('run-1');
        self::assertNotNull($found);
        self::assertSame(RunStage::Uploading, $found->stage);
        self::assertSame('tus-1', $found->lastUploadIdentifier);
        self::assertSame('cid', $found->publishCid);
        self::assertSame('website', $found->websiteId);
        self::assertSame('ipns', $found->ipnsKey);
    }

    public function testCreatePersistsARunAndReturnsTrueWhenAbsent(): void
    {
        $run = $this->makeRun('run-1', 1000);

        self::assertTrue($this->repository->create($run));
        self::assertSame('run-1', $this->repository->find('run-1')?->runId);
    }

    public function testCreateRejectsExistingRunIdWithoutOverwriting(): void
    {
        $first = $this->makeRun('run-1', 1000);
        self::assertTrue($this->repository->create($first));

        $second = ExportRun::fromArray($first->toArray());
        $second->start(at: 1001);

        self::assertFalse($this->repository->create($second));
        $found = $this->repository->find('run-1');
        self::assertNotNull($found);
        self::assertSame(RunStatus::NotStarted, $found->status);
    }

    public function testCreateStoresDistinctRunIds(): void
    {
        self::assertTrue($this->repository->create($this->makeRun('run-1', 1000)));
        self::assertTrue($this->repository->create($this->makeRun('run-2', 2000)));

        self::assertCount(2, $this->repository->list());
    }
}
