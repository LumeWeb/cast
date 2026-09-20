<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Upload;

use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Publish\PollPolicy;
use LumeWeb\Cast\Publish\UploadIdentifier;
use LumeWeb\Cast\Publish\UploadStatus;
use LumeWeb\Cast\Tests\Unit\Publish\FakePublishClock;
use LumeWeb\Cast\Tests\Unit\Support\RecordingTransport;
use LumeWeb\Cast\Upload\UploadResultClient;
use LumeWeb\Cast\Upload\UploadResultPoller;
use PHPUnit\Framework\TestCase;

/**
 * The bounded result-polling service against the real UploadResultClient:
 * completed/duplicate are terminal success, failed is a terminal error whose
 * message detail is preserved, pending/processing are re-polled with backoff,
 * and the poll budget is bounded by an injected PollPolicy so a tick always
 * yields. The injected PublishClock records sleeps for assertion — the loop
 * never blocks on a real sleep in tests.
 */
final class UploadResultPollerTest extends TestCase
{
    private const TOKEN = 's3cret-bearer-token-9f8d';
    private const BASE_URL = 'https://portal.test';

    private FakePublishClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FakePublishClock();
    }

    public function testCompletedReturnsImmediatelyWithoutSleep(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"status":"completed","cid":"QmDone"}'),
        ]);

        $result = $this->poller($recording)->wait(new UploadIdentifier('id-1'));

        self::assertTrue($result->isSuccess());
        self::assertSame('QmDone', $result->cid);
        self::assertSame([], $this->clock->slept);
        self::assertCount(1, $recording->requests());
    }

    public function testDuplicateReturnsImmediatelyWithoutSleep(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"status":"duplicate","cid":"QmDup"}'),
        ]);

        $result = $this->poller($recording)->wait(new UploadIdentifier('id-1'));

        self::assertTrue($result->isSuccess());
        self::assertSame('QmDup', $result->cid);
        self::assertSame([], $this->clock->slept);
    }

    public function testFailedReturnsImmediatelyWithMessagePreserved(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"status":"failed","error":"checksum mismatch"}'),
        ]);

        $result = $this->poller($recording)->wait(new UploadIdentifier('id-1'));

        self::assertSame(UploadStatus::Failed, $result->status);
        self::assertSame('checksum mismatch', $result->message);
        self::assertFalse($result->isSuccess());
        self::assertSame([], $this->clock->slept);
    }

    public function testPollsPendingAndProcessingWithBackoffUntilCompleted(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"status":"pending"}'),
            new Response(200, [], '{"status":"processing"}'),
            new Response(200, [], '{"status":"completed","cid":"QmDone"}'),
        ]);

        $result = $this->poller($recording)->wait(new UploadIdentifier('id-1'));

        self::assertSame('QmDone', $result->cid);
        self::assertSame([2, 3], $this->clock->slept);
        $this->assertEachRequestPollsSameIdentifier($recording);
    }

    public function testReturnsFailedAfterPendingWithoutFurtherPolls(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"status":"pending"}'),
            new Response(200, [], '{"status":"failed","error":"archive unpack failed"}'),
        ]);

        $result = $this->poller($recording)->wait(new UploadIdentifier('id-1'));

        self::assertSame(UploadStatus::Failed, $result->status);
        self::assertSame('archive unpack failed', $result->message);
        self::assertSame([2], $this->clock->slept);
        self::assertCount(2, $recording->requests());
    }

    public function testReturnsLastResultWhenPollingBudgetIsExhausted(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"status":"pending"}'),
            new Response(200, [], '{"status":"pending"}'),
            new Response(200, [], '{"status":"pending"}'),
        ]);

        $result = $this->poller($recording, new PollPolicy(maxPolls: 3))->wait(new UploadIdentifier('id-1'));

        self::assertSame(UploadStatus::Pending, $result->status);
        self::assertCount(3, $recording->requests());
        self::assertSame([2, 3], $this->clock->slept);
    }

    private function poller(RecordingTransport $recording, ?PollPolicy $policy = null): UploadResultPoller
    {
        return new UploadResultPoller(
            new UploadResultClient($recording->transport(), self::BASE_URL, self::TOKEN),
            $this->clock,
            $policy ?? new PollPolicy(maxPolls: 30),
        );
    }

    private function assertEachRequestPollsSameIdentifier(RecordingTransport $recording): void
    {
        $expected = self::BASE_URL . '/api/upload/result/id-1';
        $uris = array_map(
            static fn ($request): string => (string) $request->getUri(),
            $recording->requests(),
        );
        self::assertSame([$expected, $expected, $expected], $uris);
    }
}
