<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The pure outcome of one bounded pipeline stage unit.
 *
 *  - more()    the stage made progress; more ticks are needed;
 *  - done()    the stage boundary is complete;
 *  - fail(...) the unit failed with a safe, non-credential reason;
 *  - cancel()  the unit asks the whole pipeline to stop cleanly.
 *
 * Besides the done/failure shape, a result carries the stage's next opaque
 * resume cursor, an optional progress delta to add to the run, and a list of
 * warnings the run should record. The orchestrator applies these to the run
 * aggregate; a stage never mutates the run itself.
 */
final class StageResult
{
    /**
     * @param list<string> $warnings
     */
    private function __construct(
        public readonly bool $done,
        public readonly ?string $failure,
        public readonly string $cursor,
        public readonly int $progress,
        public readonly bool $cancelled,
        public readonly array $warnings,
    ) {
    }

    /**
     * @param list<string> $warnings
     */
    public static function more(string $cursor, int $progress = 0, array $warnings = []): self
    {
        return new self(false, null, $cursor, $progress, false, $warnings);
    }

    /**
     * @param list<string> $warnings
     */
    public static function done(string $cursor = '', int $progress = 0, array $warnings = []): self
    {
        return new self(true, null, $cursor, $progress, false, $warnings);
    }

    /**
     * @param list<string> $warnings
     */
    public static function fail(string $reason, string $cursor = '', int $progress = 0, array $warnings = []): self
    {
        return new self(false, $reason, $cursor, $progress, false, $warnings);
    }

    /**
     * @param list<string> $warnings
     */
    public static function cancel(string $cursor = '', int $progress = 0, array $warnings = []): self
    {
        return new self(false, null, $cursor, $progress, true, $warnings);
    }
}
