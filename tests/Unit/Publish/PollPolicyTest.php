<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use LumeWeb\Cast\Publish\PollPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Backoff shape for result polling: 2s base, ×1.5 per pending observation,
 * capped at 30s, and a bounded poll budget so the publish job always yields.
 */
final class PollPolicyTest extends TestCase
{
    public function testBackoffGrowsByFactorFromTheTwoSecondBase(): void
    {
        $policy = new PollPolicy();

        self::assertSame(2, $policy->delayFor(1));
        self::assertSame(3, $policy->delayFor(2));
        self::assertSame(4, $policy->delayFor(3));
        self::assertSame(6, $policy->delayFor(4));
    }

    public function testBackoffIsCappedAtThirtySeconds(): void
    {
        $policy = new PollPolicy();

        self::assertSame(30, $policy->delayFor(20));
        self::assertSame(30, $policy->delayFor(100));
    }

    public function testPollBudgetIsBoundedByDefault(): void
    {
        self::assertSame(30, (new PollPolicy())->maxPolls());
        self::assertSame(4, (new PollPolicy(maxPolls: 4))->maxPolls());
    }
}
