<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export\Rewrite;

use LumeWeb\Cast\Export\Rewrite\DestinationMode;
use PHPUnit\Framework\TestCase;

final class DestinationModeTest extends TestCase
{
    public function testOfflineZipIsTheOnlyBackedMode(): void
    {
        self::assertSame('offline-zip', DestinationMode::OfflineZip->value);
    }

    public function testFromStringAcceptsOfflineZipAliases(): void
    {
        self::assertSame(DestinationMode::OfflineZip, DestinationMode::fromString('offline-zip'));
        self::assertSame(DestinationMode::OfflineZip, DestinationMode::fromString('offline'));
        self::assertSame(DestinationMode::OfflineZip, DestinationMode::fromString('zip'));
    }

    public function testFromStringRejectsUnsupportedModes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DestinationMode::fromString('absolute-host');
    }

    public function testFromStringRejectsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DestinationMode::fromString('');
    }
}
