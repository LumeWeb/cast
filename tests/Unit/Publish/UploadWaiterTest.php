<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use LumeWeb\Cast\Publish\PollPolicy;
use LumeWeb\Cast\Publish\UploadIdentifier;
use LumeWeb\Cast\Publish\UploadResult;
use LumeWeb\Cast\Publish\UploadRoute;
use LumeWeb\Cast\Publish\UploadSpec;
use LumeWeb\Cast\Publish\UploadStatus;
use LumeWeb\Cast\Publish\UploadWaiter;
use PHPUnit\Framework\TestCase;

/**
 * The upload + result-polling gateway: delegates the upload to the client and
 * then waits, with bounded backoff, for a terminal result. Pending/processing
 * responses are re-polled; the exact interpreted terminal vocabulary lives on
 * UploadStatus.
 */
final class UploadWaiterTest extends TestCase
{
    private FakePublishClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FakePublishClock();
    }

    public function testUploadDelegatesToClientAndReturnsIdentifier(): void
    {
        $uploads = new FakeUploadClient([new UploadResult(UploadStatus::Completed, cid: 'QmDone')]);
        $waiter = new UploadWaiter($uploads, $this->clock);
        $spec = new UploadSpec('/tmp/cast-export/run.zip', 'run.zip', archive: true, sizeBytes: 10, route: UploadRoute::Post);

        $identifier = $waiter->upload($spec);

        self::assertSame('id-1', (string) $identifier);
        self::assertSame([$spec], $uploads->specs);
    }

    public function testReturnsTerminalResultWithoutSleepingWhenFirstPollCompletes(): void
    {
        $uploads = new FakeUploadClient([new UploadResult(UploadStatus::Completed, cid: 'QmDone')]);
        $waiter = new UploadWaiter($uploads, $this->clock);

        $result = $waiter->wait(new UploadIdentifier('id-1'));

        self::assertTrue($result->isSuccess());
        self::assertSame('QmDone', $result->cid);
        self::assertSame([], $this->clock->slept);
        self::assertSame(['id-1'], array_map(static fn (UploadIdentifier $id): string => $id->value, $uploads->polled));
    }

    public function testPollsPendingUntilTerminalSleepingWithBackoffBetweenPolls(): void
    {
        $uploads = new FakeUploadClient([
            new UploadResult(UploadStatus::Processing),
            new UploadResult(UploadStatus::Pending),
            new UploadResult(UploadStatus::Completed, cid: 'QmDone'),
        ]);
        $waiter = new UploadWaiter($uploads, $this->clock);

        $result = $waiter->wait(new UploadIdentifier('id-1'));

        self::assertSame('QmDone', $result->cid);
        self::assertSame([2, 3], $this->clock->slept);
        self::assertCount(3, $uploads->polled);
    }

    public function testDuplicateIsTreatedAsTerminalSuccess(): void
    {
        $uploads = new FakeUploadClient([new UploadResult(UploadStatus::Duplicate, cid: 'QmDup')]);
        $waiter = new UploadWaiter($uploads, $this->clock);

        $result = $waiter->wait(new UploadIdentifier('id-1'));

        self::assertTrue($result->isSuccess());
        self::assertSame('QmDup', $result->cid);
        self::assertSame([], $this->clock->slept);
    }

    public function testReturnsLastPollWhenPollingBudgetIsExhausted(): void
    {
        $pending = new UploadResult(UploadStatus::Pending);
        $uploads = new FakeUploadClient([], loopResponse: $pending);
        $waiter = new UploadWaiter($uploads, $this->clock, new PollPolicy(maxPolls: 3));

        $result = $waiter->wait(new UploadIdentifier('id-1'));

        self::assertSame(UploadStatus::Pending, $result->status);
        self::assertCount(3, $uploads->polled);
        self::assertSame([2, 3], $this->clock->slept);
    }
}
