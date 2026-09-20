<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Immutable keyset cursor for the bounded filesystem walk (theme, plugins,
 * uploads, safe wp-includes subsets). Entry names are compared in the same
 * ascending order the walk visits them; after() records the last entry seen so
 * the next batch continues strictly after it. Each cursor carries a page limit
 * (default 500 entries) and never requires building the full directory listing
 * at once.
 */
final class DirectoryCursor implements ResumeCursor
{
    public function __construct(
        private readonly int $limit = 500,
        private readonly ?string $lastEntry = null,
    ) {
        if ($limit < 1) {
            throw new \InvalidArgumentException('DirectoryCursor limit must be >= 1');
        }
        if ($lastEntry !== null && $lastEntry === '') {
            throw new \InvalidArgumentException('DirectoryCursor lastEntry must not be empty');
        }
    }

    public function lastEntry(): ?string
    {
        return $this->lastEntry;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function hasMore(int $batchSize): bool
    {
        return $batchSize >= $this->limit;
    }

    /**
     * Resume after the given entry. Refuses to move backwards so a resumed
     * walk can never re-read entries processed by an earlier batch.
     */
    public function after(string $lastEntry): self
    {
        if ($this->lastEntry !== null && $lastEntry <= $this->lastEntry) {
            throw new \InvalidArgumentException(
                sprintf('DirectoryCursor resume must advance past %s, got %s', $this->lastEntry, $lastEntry)
            );
        }

        return new self($this->limit, $lastEntry);
    }

    public function toToken(): string
    {
        return $this->lastEntry === null ? 'dir:' : 'dir:' . base64_encode($this->lastEntry);
    }

    public static function fromToken(string $token): self
    {
        $parts = explode(':', $token, 2);
        if (count($parts) !== 2 || $parts[0] !== 'dir') {
            throw new \InvalidArgumentException(sprintf('Malformed DirectoryCursor token %s', $token));
        }

        if ($parts[1] === '') {
            return new self(500);
        }

        $lastEntry = base64_decode($parts[1], true);
        if ($lastEntry === false || $lastEntry === '') {
            throw new \InvalidArgumentException(sprintf('Malformed DirectoryCursor token %s', $token));
        }

        return new self(500, $lastEntry);
    }
}
