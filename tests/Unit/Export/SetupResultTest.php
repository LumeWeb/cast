<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use InvalidArgumentException;
use LumeWeb\Cast\Export\SetupResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The persisted serialization of the setup runtime state: the jailed work
 * directory must survive a strict round-trip and refuse malformed values.
 */
final class SetupResultTest extends TestCase
{
    public function testSerializesLosslessly(): void
    {
        $setup = new SetupResult('/tmp/uploads/cast-work/run-1');

        $restored = SetupResult::fromArray($setup->toArray());

        self::assertSame('/tmp/uploads/cast-work/run-1', $restored->workDir);
        self::assertSame($setup->toArray(), $restored->toArray());
    }

    #[DataProvider('malformedSetupStateProvider')]
    public function testFromArrayRejectsMalformedState(mixed $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        SetupResult::fromArray($data);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedSetupStateProvider(): iterable
    {
        yield 'not an array' => ['workdir'];
        yield 'missing work_dir' => [[]];
        yield 'empty work_dir' => [['work_dir' => '']];
        yield 'non-string work_dir' => [['work_dir' => 5]];
    }
}
