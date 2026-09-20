<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Pure retry decision for one capture attempt, never sleeping.
 *
 * A work item is tried at most MAX_ATTEMPTS times; delaySeconds() returns the
 * exponential backoff (5s, 10s, 20s, …) the caller may schedule for the next
 * attempt, capped at two minutes, but this class never blocks.
 */
final class RetryPolicy
{
    public const MAX_ATTEMPTS = 3;
    public const BASE_DELAY_SECONDS = 5;
    public const MAX_DELAY_SECONDS = 120;

    public function shouldRetry(CaptureOutcome $outcome, int $attemptsUsed): bool
    {
        if (!$outcome->isRetryable()) {
            return false;
        }

        return $attemptsUsed < self::MAX_ATTEMPTS;
    }

    /**
     * Delay to wait before the attempt that follows attemptsUsed completed
     * ones. Without any prior attempt the base 5 seconds applies.
     */
    public function delaySeconds(int $attemptsUsed): int
    {
        $delay = self::BASE_DELAY_SECONDS * (2 ** max(0, $attemptsUsed - 1));

        return min(self::MAX_DELAY_SECONDS, $delay);
    }
}
