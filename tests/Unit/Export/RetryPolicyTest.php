<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\CaptureOutcome;
use LumeWeb\Cast\Export\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    private RetryPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new RetryPolicy();
    }

    public function testRetryableOutcomesRetryUntilMaxAttempts(): void
    {
        // 200/empty-body and 5xx/transport failures are retryable.
        self::assertTrue($this->policy->shouldRetry(CaptureOutcome::Empty, 1));
        self::assertTrue($this->policy->shouldRetry(CaptureOutcome::Empty, 2));
        self::assertTrue($this->policy->shouldRetry(CaptureOutcome::Failed, 1));
        self::assertTrue($this->policy->shouldRetry(CaptureOutcome::Failed, 2));
    }

    public function testMaxAttemptsIsThree(): void
    {
        self::assertSame(3, RetryPolicy::MAX_ATTEMPTS);

        self::assertFalse($this->policy->shouldRetry(CaptureOutcome::Empty, 3));
        self::assertFalse($this->policy->shouldRetry(CaptureOutcome::Failed, 3));
    }

    public function testPermanentOutcomesNeverRetry(): void
    {
        foreach (self::permanentOutcomes() as $outcome) {
            self::assertFalse(
                $this->policy->shouldRetry($outcome, 1),
                sprintf('%s must not be retried', $outcome->value)
            );
        }
    }

    public function testExponentialDelayStartsAtFiveSeconds(): void
    {
        self::assertSame(5, $this->policy->delaySeconds(1));
        self::assertSame(10, $this->policy->delaySeconds(2));
        self::assertSame(20, $this->policy->delaySeconds(3));
        self::assertSame(40, $this->policy->delaySeconds(4));
    }

    public function testDelayIsCappedAtTwoMinutes(): void
    {
        self::assertSame(120, $this->policy->delaySeconds(6));
        self::assertSame(120, $this->policy->delaySeconds(10));
    }

    /**
     * @return list<CaptureOutcome>
     */
    private static function permanentOutcomes(): array
    {
        return [
            CaptureOutcome::Copied,
            CaptureOutcome::Fetched,
            CaptureOutcome::Redirected,
            CaptureOutcome::CanonicalTwin,
            CaptureOutcome::OffOrigin,
            CaptureOutcome::NotFound,
            CaptureOutcome::Forbidden,
            CaptureOutcome::Ghost,
        ];
    }
}
