<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use InvalidArgumentException;
use LumeWeb\Cast\Export\DiscoverResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * {@see DiscoverResult} persisted shape: how many distinct work items the
 * discovery stage enqueued, carried across WP-Cron requests so a fresh request
 * rehydrates the shared PipelineState instead of re-running discovery.
 */
final class DiscoverResultTest extends TestCase
{
    public function testToArraySerializesTheEnqueuedCount(): void
    {
        self::assertSame(
            ['enqueued' => 42],
            (new DiscoverResult(42))->toArray(),
        );
    }

    public function testFromArrayRoundTripsTheEnqueuedCountLosslessly(): void
    {
        $restored = DiscoverResult::fromArray(['enqueued' => 42]);

        self::assertSame(42, $restored->enqueued);
        self::assertSame(['enqueued' => 42], $restored->toArray());
    }

    public function testFromArrayAcceptsAZeroEnqueuedCount(): void
    {
        $restored = DiscoverResult::fromArray(['enqueued' => 0]);

        self::assertSame(0, $restored->enqueued);
    }

    #[DataProvider('malformedDiscoverResultProvider')]
    public function testFromArrayRejectsMalformedData(mixed $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        DiscoverResult::fromArray($data);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedDiscoverResultProvider(): iterable
    {
        yield 'not an array' => ['42'];
        yield 'missing enqueued' => [[]];
        yield 'string enqueued' => [['enqueued' => '42']];
        yield 'negative enqueued' => [['enqueued' => -1]];
    }
}
