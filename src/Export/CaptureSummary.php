<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

use InvalidArgumentException;

/**
 * The outcome of the capture stage once the work queue reaches its fixed
 * point: how many rows capture terminally recorded as done, failed and
 * skipped. Read from the repository when every queued item has drained, so a
 * fresh stage instance restarting from a persisted run always reports the same
 * counts — the stage accumulates nothing in memory.
 *
 * Named CaptureSummary (not CaptureResult) because the per-item capture
 * outcome value object already owns the CaptureResult name.
 *
 * The value also knows its persisted shape ({@see toArray()} / {@see
 * fromArray()}) so ExportRun can carry the capture outcome across WP-Cron
 * requests and rehydrate the shared PipelineState without re-running capture.
 */
final class CaptureSummary
{
    public function __construct(
        public readonly int $done,
        public readonly int $failed,
        public readonly int $skipped,
    ) {
    }

    /**
     * @return array{done: int, failed: int, skipped: int}
     */
    public function toArray(): array
    {
        return [
            'done' => $this->done,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
        ];
    }

    /**
     * Strict, lossless read-back of a persisted capture summary. Any malformed
     * part is rejected loudly so a corrupt run row is never silently
     * reinterpreted.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new InvalidArgumentException('Capture summary is not an array.');
        }

        $done = $data['done'] ?? null;
        if (!is_int($done) || $done < 0) {
            throw new InvalidArgumentException('Capture summary is malformed.');
        }

        $failed = $data['failed'] ?? null;
        if (!is_int($failed) || $failed < 0) {
            throw new InvalidArgumentException('Capture summary is malformed.');
        }

        $skipped = $data['skipped'] ?? null;
        if (!is_int($skipped) || $skipped < 0) {
            throw new InvalidArgumentException('Capture summary is malformed.');
        }

        return new self($done, $failed, $skipped);
    }
}
