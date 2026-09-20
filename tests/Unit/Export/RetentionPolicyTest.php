<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\RetentionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure artifact retention policy: reads the `cast_publish_retention_days`
 * option (default 7 days) into a safe effective window, where 0 disables GC
 * and any missing/invalid/negative value falls back to the default. All
 * expiry arithmetic is pure clock math; the policy never touches a filesystem
 * or WordPress.
 */
final class RetentionPolicyTest extends TestCase
{
    public function testOptionNameIsCastPublishRetentionDays(): void
    {
        self::assertSame('cast_publish_retention_days', RetentionPolicy::OPTION);
    }

    public function testDefaultIsSevenDays(): void
    {
        self::assertSame(7, RetentionPolicy::default()->retentionDays);
        self::assertSame(7, RetentionPolicy::DEFAULT_RETENTION_DAYS);
    }

    public function testUnsetOptionFallsBackToSevenDays(): void
    {
        self::assertSame(7, RetentionPolicy::fromOption(null)->retentionDays);
        self::assertSame(7, RetentionPolicy::fromOption(false)->retentionDays);
    }

    /**
     * @param mixed $stored
     */
    #[DataProvider('validDayOptions')]
    public function testValidDayOptionsAreRead(mixed $stored, int $expected): void
    {
        self::assertSame($expected, RetentionPolicy::fromOption($stored)->retentionDays);
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function validDayOptions(): array
    {
        return [
            'integer days' => [7, 7],
            'numeric string' => ['7', 7],
            'one day' => ['1', 1],
            'thirty days' => ['30', 30],
            'zero padded string' => ['007', 7],
        ];
    }

    public function testZeroDisablesRetention(): void
    {
        self::assertTrue(RetentionPolicy::fromOption(0)->isDisabled());
        self::assertTrue(RetentionPolicy::fromOption('0')->isDisabled());
        self::assertTrue(RetentionPolicy::disabled()->isDisabled());
    }

    /**
     * @param mixed $stored
     */
    #[DataProvider('invalidDayOptions')]
    public function testInvalidValuesFallBackToTheSafeDefault(mixed $stored): void
    {
        $policy = RetentionPolicy::fromOption($stored);

        // A corrupt option must never shorten (or disable) retention: it reads
        // as the 7-day default, and is never silently disabled.
        self::assertSame(7, $policy->retentionDays);
        self::assertFalse($policy->isDisabled());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidDayOptions(): array
    {
        return [
            'garbage string' => ['bogus'],
            'empty string' => [''],
            'decimal string' => ['7.5'],
            'negative integer' => [-1],
            'negative string' => ['-2'],
            'array' => [['7']],
        ];
    }

    public function testNegativeDaysConstructorFallsBackToDefault(): void
    {
        self::assertSame(7, RetentionPolicy::fromDays(-5)->retentionDays);
    }

    public function testCutoffIsNowMinusDaysInSeconds(): void
    {
        $policy = RetentionPolicy::fromDays(7);

        self::assertSame(1_000_000 - 7 * 86400, $policy->cutoff(1_000_000));
    }

    public function testIsExpiredUsesStrictCutoffBoundary(): void
    {
        $policy = RetentionPolicy::fromDays(7);
        $now = 2_000_000_000;
        $cutoff = $policy->cutoff($now);

        // An artifact modified exactly at the cutoff is NOT yet expired.
        self::assertFalse($policy->isExpired($cutoff, $now));
        // One second before the cutoff is expired.
        self::assertTrue($policy->isExpired($cutoff - 1, $now));
        // A fresh artifact is never expired.
        self::assertFalse($policy->isExpired($now, $now));
    }

    public function testDisabledPolicyNeverExpiresAnything(): void
    {
        $policy = RetentionPolicy::disabled();

        // Even an artifact from the distant past must never be expired while
        // retention is disabled, so a caller that forgets the disabled check
        // can still never collect behind an explicit 0.
        self::assertFalse($policy->isExpired(0, 2_000_000_000));
        self::assertSame(2_000_000_000, $policy->cutoff(2_000_000_000));
    }
}
