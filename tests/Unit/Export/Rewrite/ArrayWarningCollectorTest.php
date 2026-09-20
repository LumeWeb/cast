<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export\Rewrite;

use LumeWeb\Cast\Export\Rewrite\ArrayWarningCollector;
use LumeWeb\Cast\Export\Rewrite\WarningCollector;
use PHPUnit\Framework\TestCase;

final class ArrayWarningCollectorTest extends TestCase
{
    private ArrayWarningCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new ArrayWarningCollector();
    }

    public function testStartsEmpty(): void
    {
        self::assertTrue($this->collector->isEmpty());
        self::assertSame([], $this->collector->all());
    }

    public function testRecordsCategoryAndMessage(): void
    {
        $this->collector->record(WarningCollector::ORIGIN_LEFTOVER, 'about/index.html');

        self::assertFalse($this->collector->isEmpty());
        self::assertSame(
            [['category' => 'leftover_origin', 'message' => 'about/index.html']],
            $this->collector->all()
        );
    }

    public function testRecordsInOrder(): void
    {
        $this->collector->record('a', 'one');
        $this->collector->record('b', 'two');

        self::assertSame(
            [
                ['category' => 'a', 'message' => 'one'],
                ['category' => 'b', 'message' => 'two'],
            ],
            $this->collector->all()
        );
    }

    public function testHasLeftoverOriginAfterOneRecord(): void
    {
        $this->collector->record(WarningCollector::ORIGIN_LEFTOVER, 'x');

        self::assertTrue($this->collector->hasLeftoverOrigin());
    }
}
