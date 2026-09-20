<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * What capture decided for one work item: the outcome, the written relative
 * path and content hash when content was accepted, an optional redirect
 * target, and the bound the caller needs to schedule retries (attempts used
 * plus the exponential delay for the next one). No persistence lives here.
 */
final class CaptureResult
{
    public function __construct(
        public readonly CaptureOutcome $outcome,
        public readonly ?string $outputPath = null,
        public readonly ?string $contentHash = null,
        public readonly ?string $redirectTarget = null,
        public readonly int $attempts = 1,
        public readonly int $retryDelaySeconds = 0,
        public readonly ?string $detail = null,
    ) {
    }
}
