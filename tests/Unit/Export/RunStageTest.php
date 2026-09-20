<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\RunStage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunStageTest extends TestCase
{
    #[DataProvider('orderProvider')]
    public function testOrderAssignsPipelinePositions(RunStage $stage, int $expected): void
    {
        self::assertSame($expected, $stage->order());
    }

    /**
     * @return iterable<string, array{RunStage, int}>
     */
    public static function orderProvider(): iterable
    {
        yield 'idle' => [RunStage::Idle, 0];
        yield 'exporting' => [RunStage::Exporting, 1];
        yield 'uploading' => [RunStage::Uploading, 2];
        yield 'publishing' => [RunStage::Publishing, 3];
        yield 'finished' => [RunStage::Finished, 4];
    }

    #[DataProvider('legalAdvanceProvider')]
    public function testCanAdvanceToOnlyImmediateNextStage(RunStage $from, RunStage $to): void
    {
        self::assertTrue($from->canAdvanceTo($to));
    }

    /**
     * @return iterable<string, array{RunStage, RunStage}>
     */
    public static function legalAdvanceProvider(): iterable
    {
        yield 'idle to exporting' => [RunStage::Idle, RunStage::Exporting];
        yield 'exporting to uploading' => [RunStage::Exporting, RunStage::Uploading];
        yield 'uploading to publishing' => [RunStage::Uploading, RunStage::Publishing];
        yield 'publishing to finished' => [RunStage::Publishing, RunStage::Finished];
    }

    #[DataProvider('illegalAdvanceProvider')]
    public function testCanAdvanceToRejectsRegressionSkipAndSelf(RunStage $from, RunStage $to): void
    {
        self::assertFalse($from->canAdvanceTo($to));
    }

    /**
     * @return iterable<string, array{RunStage, RunStage}>
     */
    public static function illegalAdvanceProvider(): iterable
    {
        yield 'idle to itself' => [RunStage::Idle, RunStage::Idle];
        yield 'idle skips to uploading' => [RunStage::Idle, RunStage::Uploading];
        yield 'exporting regresses to idle' => [RunStage::Exporting, RunStage::Idle];
        yield 'uploading skips to finished' => [RunStage::Uploading, RunStage::Finished];
        yield 'finished to anything' => [RunStage::Finished, RunStage::Exporting];
    }

    #[DataProvider('knownStageProvider')]
    public function testFromStoredMapsKnownValue(string $value, RunStage $expected): void
    {
        self::assertSame($expected, RunStage::fromStored($value));
    }

    /**
     * @return iterable<string, array{string, RunStage}>
     */
    public static function knownStageProvider(): iterable
    {
        yield 'idle' => ['idle', RunStage::Idle];
        yield 'exporting' => ['exporting', RunStage::Exporting];
        yield 'uploading' => ['uploading', RunStage::Uploading];
        yield 'publishing' => ['publishing', RunStage::Publishing];
        yield 'finished' => ['finished', RunStage::Finished];
    }

    #[DataProvider('unrecognizedStageProvider')]
    public function testFromStoredDefaultsToIdleForUnrecognizedValue(mixed $value): void
    {
        self::assertSame(RunStage::Idle, RunStage::fromStored($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unrecognizedStageProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'null' => [null];
        yield 'random text' => ['exporting_now'];
        yield 'non-string' => [42];
    }
}
