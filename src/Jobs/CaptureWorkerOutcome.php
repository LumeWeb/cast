<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * The result of one {@see CaptureWorker::work()} call: the outcome kind
 * plus, only for a real capture, the canonical identity of the captured
 * item.
 */
final class CaptureWorkerOutcome
{
    private function __construct(
        public readonly CaptureWorkerOutcomeKind $kind,
        public readonly ?string $identity = null,
    ) {
    }

    public static function captured(string $identity): self
    {
        return new self(CaptureWorkerOutcomeKind::Captured, $identity);
    }

    public static function skippedMissing(): self
    {
        return new self(CaptureWorkerOutcomeKind::SkippedMissing);
    }

    public static function skippedTerminal(): self
    {
        return new self(CaptureWorkerOutcomeKind::SkippedTerminal);
    }

    public static function skippedSuperseded(): self
    {
        return new self(CaptureWorkerOutcomeKind::SkippedSuperseded);
    }

    public static function skippedNotAtCapture(): self
    {
        return new self(CaptureWorkerOutcomeKind::SkippedNotAtCapture);
    }

    public static function skippedNotReady(): self
    {
        return new self(CaptureWorkerOutcomeKind::SkippedNotReady);
    }

    public static function skippedIdle(): self
    {
        return new self(CaptureWorkerOutcomeKind::SkippedIdle);
    }
}
