<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Immutable keyset cursor over post IDs (published posts/pages/CPTs).
 *
 * Pages are fetched with `ID > lastId ORDER BY ID LIMIT limit`; after a batch
 * the maximum returned ID becomes the next cursor. hasMore() is a pure
 * function of how many rows the last batch produced, so resume behaviour is
 * deterministic and never requires materialising an unbounded array.
 */
final class PostIdCursor implements ResumeCursor
{
    public function __construct(
        private readonly int $lastId = 0,
        private readonly int $limit = 50,
    ) {
        if ($lastId < 0) {
            throw new \InvalidArgumentException('PostIdCursor lastId must be >= 0');
        }
        if ($limit < 1) {
            throw new \InvalidArgumentException('PostIdCursor limit must be >= 1');
        }
    }

    public function lastId(): int
    {
        return $this->lastId;
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
     * Resume after the given post ID. Refuses to go backwards so a resume
     * cursor can never loop over the same rows.
     */
    public function next(int $lastSeenId): self
    {
        if ($lastSeenId <= $this->lastId) {
            throw new \InvalidArgumentException(
                sprintf('PostIdCursor resume must advance past %d, got %d', $this->lastId, $lastSeenId)
            );
        }

        return new self($lastSeenId, $this->limit);
    }

    public function toToken(): string
    {
        return sprintf('post:%d:%d', $this->lastId, $this->limit);
    }

    public static function fromToken(string $token): self
    {
        $parts = explode(':', $token);
        if (count($parts) !== 3 || $parts[0] !== 'post') {
            throw new \InvalidArgumentException(sprintf('Malformed PostIdCursor token %s', $token));
        }

        if ($parts[1] === '' || !ctype_digit($parts[1]) || $parts[2] === '' || !ctype_digit($parts[2])) {
            throw new \InvalidArgumentException(sprintf('Malformed PostIdCursor token %s', $token));
        }

        return new self((int) $parts[1], (int) $parts[2]);
    }
}
