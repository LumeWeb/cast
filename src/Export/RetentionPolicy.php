<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Pure artifact retention policy, driven by the `cast_publish_retention_days`
 * option.
 *
 * The effective window is the 7-day default unless the option holds a
 * non-negative whole number of days. An explicit `0` disables retention GC
 * entirely (every artifact is kept); a missing, unparseable or negative value
 * falls back to the 7-day default so a corrupt option can never accidentally
 * shorten — or silently disable — retention. Expiry is pure clock arithmetic:
 * the policy never touches the filesystem or WordPress.
 */
final class RetentionPolicy
{
    /**
     * The option holding the retention window in days (`cast_publish_retention_days`).
     */
    public const OPTION = 'cast_publish_retention_days';

    public const DEFAULT_RETENTION_DAYS = 7;

    private function __construct(public readonly int $retentionDays)
    {
    }

    /**
     * A policy with an explicit retention window in days. Negative values are
     * invalid and fall back to the safe default.
     */
    public static function fromDays(int $days): self
    {
        return $days < 0 ? self::default() : new self($days);
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_RETENTION_DAYS);
    }

    /**
     * Retention disabled: 0 days means artifacts are never collected.
     */
    public static function disabled(): self
    {
        return new self(0);
    }

    /**
     * Normalize a raw stored option value into a safe policy. `0`/`'0'`
     * disables retention; any non-negative whole number is honored; everything
     * else (missing, garbage, negative, fractional) reads as the default.
     */
    public static function fromOption(mixed $stored): self
    {
        if ($stored === 0 || $stored === '0') {
            return self::disabled();
        }

        $days = null;
        if (is_int($stored)) {
            $days = $stored;
        } elseif (is_string($stored) && preg_match('/^\d+$/', $stored) === 1) {
            $days = (int) $stored;
        }

        if ($days === null || $days < 0) {
            return self::default();
        }

        return $days === 0 ? self::disabled() : new self($days);
    }

    public function isDisabled(): bool
    {
        return $this->retentionDays === 0;
    }

    /**
     * The unix cutoff instant: an artifact modified strictly before this is
     * due for collection.
     */
    public function cutoff(int $now): int
    {
        return $now - $this->retentionDays * 86400;
    }

    /**
     * Whether an artifact modified at $modifiedAt is due for collection at
     * $now. A disabled policy never expires anything, so a caller that skips
     * the isDisabled() short-circuit still cannot collect behind an explicit 0.
     */
    public function isExpired(int $modifiedAt, int $now): bool
    {
        if ($this->isDisabled()) {
            return false;
        }

        return $modifiedAt < $this->cutoff($now);
    }
}
