<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

use InvalidArgumentException;

/**
 * The outcome of the rewrite stage once no rewritable Done item remains:
 * how many completed text-like items were rewritten and how many binary/fixed
 * items were passed through untouched, plus how many distinct asset/media rows
 * rewrite collected along the way. Read from the repository at the fixed
 * point, so a fresh stage instance restarting from a persisted run always
 * reports the same counts — the stage accumulates nothing in memory.
 *
 * Named RewriteSummary (not RewriteResult) because the per-item rewrite
 * outcome value object already reserves the RewriteResult name.
 *
 * {@see $assetsCollected} is the second half of the run's honest work total:
 * the same cumulative distinct-collected count the stage's rewrite cursor
 * carries while rewrite is in flight, pinned here at the fixed point so the
 * dashboard's denominator can include it even after the cursor moves on to
 * pack. Every collected row is a real row in the shared queue, so the
 * rewrite summary numerator (rewritten + passedThrough) can never exceed the
 * discover total plus the collected count — a collected row must exist and be
 * captured before it can ever be rewritten or passed through.
 *
 * The value also knows its persisted shape ({@see toArray()} / {@see
 * fromArray()}) so ExportRun can carry the rewrite outcome across WP-Cron
 * requests and rehydrate the shared PipelineState without re-running rewrite.
 */
final class RewriteSummary
{
    public function __construct(
        public readonly int $rewritten,
        public readonly int $passedThrough,
        /** The cumulative count of distinct asset/media rows rewrite collected
         * this run (0 when nothing was collected). Mirrors the collected count
         * the rewrite cursor persisted while the stage ran. */
        public readonly int $assetsCollected = 0,
    ) {
    }

    /**
     * @return array{rewritten: int, passed_through: int, assets_collected: int}
     */
    public function toArray(): array
    {
        return [
            'rewritten' => $this->rewritten,
            'passed_through' => $this->passedThrough,
            'assets_collected' => $this->assetsCollected,
        ];
    }

    /**
     * Strict, lossless read-back of a persisted rewrite summary. Any malformed
     * part is rejected loudly so a corrupt run row is never silently
     * reinterpreted. A missing assets_collected key reads back as 0 (legacy
     * rows persisted before the collected count was surfaced stay valid).
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new InvalidArgumentException('Rewrite summary is not an array.');
        }

        $rewritten = $data['rewritten'] ?? null;
        if (!is_int($rewritten) || $rewritten < 0) {
            throw new InvalidArgumentException('Rewrite summary is malformed.');
        }

        $passedThrough = $data['passed_through'] ?? null;
        if (!is_int($passedThrough) || $passedThrough < 0) {
            throw new InvalidArgumentException('Rewrite summary is malformed.');
        }

        $assetsCollected = $data['assets_collected'] ?? 0;
        if (!is_int($assetsCollected) || $assetsCollected < 0) {
            throw new InvalidArgumentException('Rewrite summary is malformed.');
        }

        return new self($rewritten, $passedThrough, $assetsCollected);
    }
}
