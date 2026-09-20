<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Persistence;

use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Export\RunStage;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Persistence\RunPersistenceException;
use LumeWeb\Cast\Persistence\WordPressRunRepository;
use PHPUnit\Framework\TestCase;

final class WordPressRunRepositoryTest extends TestCase
{
    private FakeOptionGateway $gateway;

    private WordPressRunRepository $repository;

    protected function setUp(): void
    {
        $this->gateway = new FakeOptionGateway();
        $this->repository = new WordPressRunRepository($this->gateway);
    }

    private function makeRun(string $id, int $at): ExportRun
    {
        return ExportRun::create($id, new RunSettings(hostname: 'blog.example.test'), at: $at);
    }

    public function testUsesASingleGlobalOptionKey(): void
    {
        self::assertSame('cast_export_run', WordPressRunRepository::OPTION_KEY);
    }

    public function testFindReturnsNullWhenOptionMissing(): void
    {
        self::assertNull($this->repository->find('run-1'));
    }

    public function testFindReturnsNullWhenStoredRunHasADifferentId(): void
    {
        $this->repository->save($this->makeRun('run-1', 1000));

        self::assertNull($this->repository->find('run-2'));
    }

    public function testFindReturnsTheMatchingStoredRun(): void
    {
        $run = $this->makeRun('run-1', 1000);
        $run->start(at: 1001);
        $this->repository->save($run);

        $found = $this->repository->find('run-1');
        self::assertNotNull($found);
        self::assertSame('run-1', $found->runId);
        self::assertSame(1001, $found->updatedAt);
    }

    public function testFindThrowsTypedPersistenceErrorOnInvalidOption(): void
    {
        $this->gateway->options[WordPressRunRepository::OPTION_KEY] = 'not-an-array';

        $this->expectException(RunPersistenceException::class);
        $this->repository->find('run-1');
    }

    public function testFindReadsStoredRunWithDifferingSchemaVersion(): void
    {
        // Pre-release: there is no released persisted data, so a differing
        // schema_version on disk is not a compatibility error — the run is read.
        $data = $this->makeRun('run-1', 1000)->toArray();
        $data['schema_version'] = 999;
        $this->gateway->options[WordPressRunRepository::OPTION_KEY] = $data;

        $found = $this->repository->find('run-1');
        self::assertNotNull($found);
        self::assertSame('run-1', $found->runId);
        self::assertSame(1, $found->toArray()['schema_version']);
    }

    public function testLatestReturnsNullWhenOptionMissing(): void
    {
        self::assertNull($this->repository->latest());
    }

    public function testLatestReturnsTheStoredRun(): void
    {
        $run = $this->makeRun('run-1', 1000);
        $run->start(at: 1002);
        $this->repository->save($run);

        $latest = $this->repository->latest();
        self::assertNotNull($latest);
        self::assertSame('run-1', $latest->runId);
        self::assertSame(1002, $latest->updatedAt);
    }

    public function testLatestThrowsTypedPersistenceErrorOnInvalidOption(): void
    {
        $this->gateway->options[WordPressRunRepository::OPTION_KEY] = 'not-an-array';

        $this->expectException(RunPersistenceException::class);
        $this->repository->latest();
    }

    public function testListIsEmptyWhenOptionMissing(): void
    {
        self::assertSame([], $this->repository->list());
    }

    public function testListReturnsTheStoredRun(): void
    {
        $this->repository->save($this->makeRun('run-1', 1000));

        self::assertCount(1, $this->repository->list());
        self::assertSame('run-1', $this->repository->list()[0]->runId);
    }

    public function testSaveWritesSerializedAggregateWithAutoloadDisabled(): void
    {
        $run = $this->makeRun('run-1', 1000);

        $this->repository->save($run);

        self::assertSame($run->toArray(), $this->gateway->options[WordPressRunRepository::OPTION_KEY]);
        self::assertFalse($this->gateway->autoload[WordPressRunRepository::OPTION_KEY]);
        // add-vs-update: saving never goes through the atomic create path.
        self::assertSame(0, $this->gateway->addCalls);
        self::assertSame(1, $this->gateway->updateCalls);
    }

    public function testSaveUpsertsTheStoredRun(): void
    {
        $first = $this->makeRun('run-1', 1000);
        $first->start(at: 1001);
        $this->repository->save($first);

        $second = ExportRun::fromArray($first->toArray());
        $second->complete(at: 1005);
        $this->repository->save($second);

        $found = $this->repository->latest();
        self::assertNotNull($found);
        self::assertSame(RunStatus::Completed, $found->status);
        self::assertSame(1005, $found->updatedAt);
        self::assertCount(1, $this->gateway->options);
    }

    public function testCreateStoresAtomicallyWithAutoloadDisabled(): void
    {
        $run = $this->makeRun('run-1', 1000);

        self::assertTrue($this->repository->create($run));
        self::assertSame(1, $this->gateway->addCalls);
        self::assertFalse($this->gateway->autoload[WordPressRunRepository::OPTION_KEY]);
        self::assertSame('run-1', $this->repository->latest()?->runId);
    }

    public function testCreateRejectsDuplicateRunWithoutOverwriting(): void
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

    public function testCreateIsRefusedWhileAnyRunIsStored(): void
    {
        $this->repository->save($this->makeRun('run-1', 1000));

        // The single global option is a presence check: a second run cannot be
        // created until the stored one is deleted, so duplicate starts are
        // impossible even across processes.
        self::assertFalse($this->repository->create($this->makeRun('run-2', 2000)));
        self::assertSame('run-1', $this->repository->latest()?->runId);
    }

    public function testDeleteRemovesTheMatchingStoredRun(): void
    {
        $this->repository->save($this->makeRun('run-1', 1000));

        $this->repository->delete('run-1');

        self::assertNull($this->repository->find('run-1'));
        self::assertNull($this->repository->latest());
        self::assertSame([], $this->repository->list());
    }

    public function testDeleteIgnoresANonMatchingRunId(): void
    {
        $this->repository->save($this->makeRun('run-1', 1000));

        $this->repository->delete('run-2');

        self::assertSame('run-1', $this->repository->latest()?->runId);
        self::assertSame(0, $this->gateway->deleteCalls);
    }

    public function testDeleteIsIdempotentWhenNothingIsStored(): void
    {
        $this->repository->delete('run-1');
        $this->repository->delete('run-1');

        self::assertNull($this->repository->latest());
        self::assertSame(0, $this->gateway->deleteCalls);
    }

    public function testDeleteAllowsANewRunToBeCreatedAfterwards(): void
    {
        $this->repository->save($this->makeRun('run-1', 1000));
        $this->repository->delete('run-1');

        self::assertTrue($this->repository->create($this->makeRun('run-2', 2000)));
        self::assertSame('run-2', $this->repository->latest()?->runId);
    }

    public function testDeleteThrowsTypedPersistenceErrorOnInvalidOption(): void
    {
        $this->gateway->options[WordPressRunRepository::OPTION_KEY] = 'not-an-array';

        $this->expectException(RunPersistenceException::class);
        $this->repository->delete('run-1');
    }

    public function testRoundTripPreservesSettingsSnapshotAndAllMetadata(): void
    {
        $run = ExportRun::create(
            'run-1',
            new RunSettings(
                // A stable post-back-compat token: the legacy 'website'
                // persisted value is intentionally re-read as 'ipfs' (see
                // RunSettings::fromArray), so the round-trip uses 'ipfs'.
                targetType: 'ipfs',
                hostname: 'blog.example.test',
                artifactName: 'site-v2',
                uploadLimitBytes: 123456,
                maxRetries: 5,
                startCursor: 'post_id:42',
            ),
            at: 1000,
        );
        $run->start(at: 1001);
        $run->advanceStage(RunStage::Exporting, at: 1002);
        $run->recordProgress(7, at: 1003);
        $run->recordRetry(at: 1004);
        $run->recordResumeCursor('post_id:45', at: 1005);
        $run->recordWarning('first warning', at: 1006);
        $run->recordUploadOffset(2048, at: 1007);
        $run->recordUploadIdentifier('tus-abc', at: 1008);
        $run->recordPublishIdentifiers('cid-1', 'web-1', 'ipns-1', at: 1009);

        $this->repository->save($run);
        $loaded = $this->repository->latest();

        self::assertNotNull($loaded);
        self::assertSame($run->settingsSnapshot(), $loaded->settingsSnapshot());
        self::assertSame($run->toArray(), $loaded->toArray());
    }

    public function testRoundTripPreservesNullOptionalFields(): void
    {
        $run = $this->makeRun('run-1', 1000);
        $this->repository->save($run);
        $loaded = $this->repository->find('run-1');

        self::assertNotNull($loaded);
        self::assertNull($loaded->lastUploadOffsetBytes);
        self::assertNull($loaded->lastUploadIdentifier);
        self::assertNull($loaded->lastError);
        self::assertNull($loaded->lastWarningMessage);
        self::assertNull($loaded->publishCid);
        self::assertNull($loaded->websiteId);
        self::assertNull($loaded->ipnsKey);
        self::assertNull($loaded->endedAt);
    }

    public function testInvalidOptionErrorDoesNotLeakTheStoredValue(): void
    {
        $this->gateway->options[WordPressRunRepository::OPTION_KEY] = 'SOME-SECRET-TOKEN';

        try {
            $this->repository->latest();
            self::fail('Expected a RunPersistenceException.');
        } catch (RunPersistenceException $e) {
            self::assertStringNotContainsString('SOME-SECRET-TOKEN', $e->getMessage());
        }
    }
}
