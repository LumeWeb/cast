<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * The result of one {@see ExportTickRunner::tick()} call: a kind plus, only
 * for failures, a safe reason string. Never carries credentials or stored
 * values — the reason is a fixed, non-echoing message.
 */
final class TickOutcome
{
    private function __construct(
        public readonly TickOutcomeKind $kind,
        public readonly ?string $reason = null,
    ) {
    }

    public static function ran(): self
    {
        return new self(TickOutcomeKind::Ran);
    }

    public static function completed(): self
    {
        return new self(TickOutcomeKind::Completed);
    }

    public static function failed(string $reason): self
    {
        return new self(TickOutcomeKind::Failed, $reason);
    }

    public static function retried(): self
    {
        return new self(TickOutcomeKind::Retried);
    }

    public static function watchdog(): self
    {
        return new self(TickOutcomeKind::Watchdog);
    }

    public static function cancelled(): self
    {
        return new self(TickOutcomeKind::Cancelled);
    }

    public static function skippedLocked(): self
    {
        return new self(TickOutcomeKind::SkippedLocked);
    }

    public static function skippedStaleLock(): self
    {
        return new self(TickOutcomeKind::SkippedStaleLock);
    }

    public static function skippedNoRun(): self
    {
        return new self(TickOutcomeKind::SkippedNoRun);
    }

    public static function skippedNotReady(): self
    {
        return new self(TickOutcomeKind::SkippedNotReady);
    }

    public static function deferred(): self
    {
        return new self(TickOutcomeKind::Deferred);
    }
}
