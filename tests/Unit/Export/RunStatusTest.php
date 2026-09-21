<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\RunStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunStatusTest extends TestCase
{
    #[DataProvider('knownStateProvider')]
    public function testFromStoredMapsKnownValue(string $value, RunStatus $expected): void
    {
        self::assertSame($expected, RunStatus::fromStored($value));
    }

    /**
     * @return iterable<string, array{string, RunStatus}>
     */
    public static function knownStateProvider(): iterable
    {
        yield 'not_started' => ['not_started', RunStatus::NotStarted];
        yield 'running' => ['running', RunStatus::Running];
        yield 'paused' => ['paused', RunStatus::Paused];
        yield 'completed' => ['completed', RunStatus::Completed];
        yield 'completed_with_warnings' => ['completed_with_warnings', RunStatus::CompletedWithWarnings];
        yield 'failed' => ['failed', RunStatus::Failed];
        yield 'cancelled' => ['cancelled', RunStatus::Cancelled];
    }

    #[DataProvider('unrecognizedProvider')]
    public function testFromStoredDefaultsToNotStartedForUnrecognizedValue(mixed $value): void
    {
        self::assertSame(RunStatus::NotStarted, RunStatus::fromStored($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unrecognizedProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'null' => [null];
        yield 'legacy in_progress' => ['in_progress'];
        yield 'random text' => ['bogus'];
        yield 'non-string' => [42];
    }

    #[DataProvider('legalTransitionProvider')]
    public function testCanTransitionToLegalMoves(RunStatus $from, RunStatus $to): void
    {
        self::assertTrue($from->canTransitionTo($to));
    }

    /**
     * @return iterable<string, array{RunStatus, RunStatus}>
     */
    public static function legalTransitionProvider(): iterable
    {
        yield 'start' => [RunStatus::NotStarted, RunStatus::Running];
        yield 'cancel before start' => [RunStatus::NotStarted, RunStatus::Cancelled];
        yield 'pause' => [RunStatus::Running, RunStatus::Paused];
        yield 'complete' => [RunStatus::Running, RunStatus::Completed];
        yield 'complete with warnings' => [RunStatus::Running, RunStatus::CompletedWithWarnings];
        yield 'fail' => [RunStatus::Running, RunStatus::Failed];
        yield 'cancel while running' => [RunStatus::Running, RunStatus::Cancelled];
        yield 'resume' => [RunStatus::Paused, RunStatus::Running];
        yield 'fail while paused' => [RunStatus::Paused, RunStatus::Failed];
        yield 'cancel while paused' => [RunStatus::Paused, RunStatus::Cancelled];
    }

    #[DataProvider('illegalTransitionProvider')]
    public function testCanTransitionToRejectsIllegalMoves(RunStatus $from, RunStatus $to): void
    {
        self::assertFalse($from->canTransitionTo($to));
    }

    /**
     * @return iterable<string, array{RunStatus, RunStatus}>
     */
    public static function illegalTransitionProvider(): iterable
    {
        yield 'pause before start' => [RunStatus::NotStarted, RunStatus::Paused];
        yield 'complete before start' => [RunStatus::NotStarted, RunStatus::Completed];
        yield 'fail before start' => [RunStatus::NotStarted, RunStatus::Failed];
        yield 'start twice' => [RunStatus::Running, RunStatus::Running];
        yield 'back to not started' => [RunStatus::Running, RunStatus::NotStarted];
        yield 'pause twice' => [RunStatus::Paused, RunStatus::Paused];
        yield 'complete while paused' => [RunStatus::Paused, RunStatus::Completed];
        yield 'start from completed' => [RunStatus::Completed, RunStatus::Running];
        yield 'pause from completed' => [RunStatus::Completed, RunStatus::Paused];
        yield 'restart completed_with_warnings' => [RunStatus::CompletedWithWarnings, RunStatus::Running];
        yield 'restart failed' => [RunStatus::Failed, RunStatus::Running];
        yield 'restart cancelled' => [RunStatus::Cancelled, RunStatus::Running];
    }

    #[DataProvider('terminalStateProvider')]
    public function testIsTerminal(RunStatus $status, bool $expected): void
    {
        self::assertSame($expected, $status->isTerminal());
    }

    /**
     * @return iterable<string, array{RunStatus, bool}>
     */
    public static function terminalStateProvider(): iterable
    {
        yield 'not_started' => [RunStatus::NotStarted, false];
        yield 'running' => [RunStatus::Running, false];
        yield 'paused' => [RunStatus::Paused, false];
        yield 'completed' => [RunStatus::Completed, true];
        yield 'completed_with_warnings' => [RunStatus::CompletedWithWarnings, true];
        yield 'failed' => [RunStatus::Failed, true];
        yield 'cancelled' => [RunStatus::Cancelled, true];
    }
}
