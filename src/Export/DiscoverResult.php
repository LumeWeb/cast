<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

use InvalidArgumentException;

/**
 * Records the outcome of the discovery stage for later stages and status: how
 * many distinct work items discovery enqueued into the shared queue. Sitemap
 * documents, seeders and the keyset all share this single counter, which is
 * read from the repository once all producers have drained.
 *
 * Deliberately carries no URL lists: the deduplicated queue itself is the
 * source of truth for what capture will fetch.
 *
 * The value also knows its persisted shape ({@see toArray()} / {@see
 * fromArray()}) so ExportRun can carry the discovery outcome across WP-Cron
 * requests and rehydrate the shared PipelineState without re-running discovery.
 */
final class DiscoverResult
{
    public function __construct(
        public readonly int $enqueued,
    ) {
    }

    /**
     * @return array{enqueued: int}
     */
    public function toArray(): array
    {
        return ['enqueued' => $this->enqueued];
    }

    /**
     * Strict, lossless read-back of a persisted discover result. Any malformed
     * part is rejected loudly so a corrupt run row is never silently
     * reinterpreted.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new InvalidArgumentException('Discover result is not an array.');
        }

        $enqueued = $data['enqueued'] ?? null;
        if (!is_int($enqueued) || $enqueued < 0) {
            throw new InvalidArgumentException('Discover result is malformed.');
        }

        return new self($enqueued);
    }
}
