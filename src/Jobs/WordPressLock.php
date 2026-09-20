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
 * lease is treated as free and reclaimed with a fresh token. Reclaim is a
 * compare-and-set on the exact stored value the reclaiming worker read, so
 * concurrent reclaims cannot clobber a claim that landed in between; claiming
 * an absent slot is an atomic create. `release` only deletes when the calling
 * worker still owns the stored token, so a stale worker can never delete
 * another worker's live (or reclaimed) lease — no unsafe unconditional option
 * delete. With the option gateway backed by update_option($option, $value,
 * false) the same TTL contract as the transient Lock interface holds and stays
 * unit-testable through a fake gateway.
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

        // Single read: the whole decision — absent vs. present, and whether a
        // present lease is still live — comes from this one stored value, so a
        // worker can never observe a freshly inserted live lease on a second
        // read and mis-route itself into a blind-overwrite reclaim.
        $stored = $this->options->get($option, null);

        $token = $this->newToken();
        $owned = [
            'token' => $token,
            'expires_at' => $now + $ttlSeconds,
            'acquired_at' => $now,
        ];

        if ($stored === null) {
            // Absent slot — free slot: claim it atomically through the
            // create-if-absent add(). Exactly one of the racing workers wins;
            // the loser's add() no-ops instead of overwriting the winner's
            // fresh lease.
            if (!$this->options->add($option, $owned, false)) {
                return false;
            }
        } else {
            $lease = $this->parseLease($stored);

            // A live lease belongs to another worker: never touch it.
            if ($lease !== null && $lease['expires_at'] > $now) {
                return false;
            }

            // Present but expired/stale/corrupt: reclaim only through a
            // compare-and-set on the exact stored value this worker read. If
            // another worker already reclaimed it (or a fresh claim landed)
            // since the read, the stored value no longer equals $stored, the
            // CAS no-ops, and this worker loses. There is no blind update()
            // fallback that could overwrite a concurrent worker's fresh lease.
            if (!$this->options->updateIfEquals($option, $owned, $stored)) {
                return false;
            }
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
        return $this->parseLease($this->options->get($option, null));
    }

    /**
     * Parse a stored raw value into a lease, or null when it is corrupt.
     *
     * @return array{token: string, expires_at: int, acquired_at: int}|null
     */
    private function parseLease(mixed $value): ?array
    {
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
