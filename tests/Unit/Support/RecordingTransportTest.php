<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Support;

use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Tests\Unit\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

/**
 * RecordingTransport's MockHandler queue is frozen at construction time;
 * appendResponses() lets a flow test top up the queue so the same transport can
 * serve follow-up requests without throwing "Mock queue is empty".
 */
final class RecordingTransportTest extends TestCase
{
    public function testAppendResponsesQueuesMoreResponsesForSubsequentRequests(): void
    {
        $recording = RecordingTransport::withResponses([new Response(200, [], 'first')]);

        $first = $recording->transport()->send('GET', 'https://example.test/first');

        $recording->appendResponses([new Response(201, [], 'second')]);

        $second = $recording->transport()->send('GET', 'https://example.test/second');

        self::assertSame(200, $first->status());
        self::assertSame(201, $second->status());
    }

    public function testAppendResponsesRejectsInvalidQueueEntries(): void
    {
        $recording = RecordingTransport::withResponses([new Response(200, [], 'first')]);

        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type (deliberately violating the documented queue contract to prove the runtime guard)
        $recording->appendResponses(['not-a-response']);
    }
}
