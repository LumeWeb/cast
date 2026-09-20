<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * Backoff and budget policy for upload-result polling: a 2s base growing by a
 * factor per pending observation, capped at 30s, with a bounded poll budget so
 * a publish tick always yields instead of blocking for the full deadline.
 */
final class PollPolicy
{
    public const DEFAULT_MAX_POLLS = 30;
    public const DEFAULT_BASE_DELAY_SECONDS = 2;
    public const DEFAULT_DELAY_FACTOR = 1.5;
    public const DEFAULT_DELAY_CAP_SECONDS = 30;

    public function __construct(
        private readonly int $maxPolls = self::DEFAULT_MAX_POLLS,
        private readonly int $baseDelaySeconds = self::DEFAULT_BASE_DELAY_SECONDS,
        private readonly float $delayFactor = self::DEFAULT_DELAY_FACTOR,
        private readonly int $delayCapSeconds = self::DEFAULT_DELAY_CAP_SECONDS,
    ) {
    }

    public function maxPolls(): int
    {
        return $this->maxPolls;
    }

    /**
     * Sleep to wait before the next poll, computed from how many pending
     * observations have already been seen.
     */
    public function delayFor(int $pendingPollsSeen): int
    {
        $delay = (int) floor($this->baseDelaySeconds * ($this->delayFactor ** max(0, $pendingPollsSeen - 1)));

        return max($this->baseDelaySeconds, min($this->delayCapSeconds, $delay));
    }
}
