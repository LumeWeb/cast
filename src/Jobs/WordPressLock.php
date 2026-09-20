<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

use LumeWeb\Cast\Persistence\OptionGateway;

/**
 * WordPress option-backed {@see Lock} adapter with lease semantics.
 *
 * Each keyed lock is one non-autoloaded option holding an owned lease:
 * a per-worker opaque token plus the absolute expiry timestamp (and when it
 * was acquired). A live lease refuses new acquirers; an expired or corrupt
 * lease is treated as free and reclaimed with a fresh token. `release` only
 * deletes when the calling worker still owns the stored token, so a stale
 * worker can never delete another worker's live (or reclaimed) lease — no
 * unsafe unconditional option delete. With the option gateway backed by
 * update_option($option, $value, false) the same TTL contract as the transient
 * Lock interface holds and stays unit-testable through a fake gateway.
 */
final class WordPressLock implements Lock
{
    public const OPTION_PREFIX = 'cast_lease_';

    /**
     * @var array<string, string> key => token this instance currently owns.
     */
    private array $tokens = [];

    public function __construct(
        private readonly OptionGateway $options,
        private readonly Clock $clock,
    ) {
    }

    public function acquire(string $key, int $ttlSeconds): bool
    {
        $now = $this->clock->now();
        $option = self::OPTION_PREFIX . $key;
        $lease = $this->readLease($option);

        if ($lease !== null && $lease['expires_at'] > $now) {
            return false;
        }

        // The raw value tells a truly absent option (free slot — claim it
        // atomically through the create-if-absent add()) apart from a
        // present-but-stale/corrupt one (reclaim in place, since add() would
        // refuse an existing option).
        $absent = $this->options->get($option, null) === null;

        $token = $this->newToken();
        $owned = [
            'token' => $token,
            'expires_at' => $now + $ttlSeconds,
            'acquired_at' => $now,
        ];

        if ($absent) {
            // Free-slot claim: add_option()-style insert wins exactly one of
            // the racing workers; the loser's add() no-ops instead of
            // overwriting the winner's fresh lease.
            if (!$this->options->add($option, $owned, false)) {
                return false;
            }
        } else {
            // Present but expired/stale/corrupt: reclaim by update. The update
            // is best-effort — no compare-and-swap, so a worker that re-read a
            // live lease could theoretically be overwritten — but WP-Cron
            // serializes ticks on a single node, and release() still verifies
            // the token before deleting, so a stale owner can never delete
            // another worker's lease.
            $this->options->update($option, $owned, false);
        }

        $this->tokens[$key] = $token;

        return true;
    }

    public function release(string $key): void
    {
        $option = self::OPTION_PREFIX . $key;
        $lease = $this->readLease($option);

        if ($lease === null) {
            return;
        }

        $token = $this->tokens[$key] ?? null;
        if ($token === null || $token !== $lease['token']) {
            return;
        }

        $this->options->delete($option);
        unset($this->tokens[$key]);
    }

    public function isHeld(string $key): bool
    {
        $lease = $this->readLease(self::OPTION_PREFIX . $key);

        return $lease !== null && $lease['expires_at'] > $this->clock->now();
    }

    public function leaseExpiresAt(string $key): ?int
    {
        $lease = $this->readLease(self::OPTION_PREFIX . $key);

        return $lease === null ? null : $lease['expires_at'];
    }

    /**
     * Read and validate a stored lease. Any value that is not exactly a
     * `{token: non-empty string, expires_at: int}` array is corrupt and is
     * reported as free so it can be reclaimed.
     *
     * @return array{token: string, expires_at: int, acquired_at: int}|null
     */
    private function readLease(string $option): ?array
    {
        $value = $this->options->get($option, null);
        if (!is_array($value)) {
            return null;
        }

        $token = $value['token'] ?? null;
        $expiresAt = $value['expires_at'] ?? null;
        if (!is_string($token) || $token === '' || !is_int($expiresAt)) {
            return null;
        }

        $acquiredAt = $value['acquired_at'] ?? 0;

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
            'acquired_at' => is_int($acquiredAt) ? $acquiredAt : 0,
        ];
    }

    private function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
