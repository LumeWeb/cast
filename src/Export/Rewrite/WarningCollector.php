<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

/**
 * Sink for non-fatal findings produced while rewriting (e.g. an origin URL
 * that survived parse-and-replace and can no longer be rewritten without
 * recomputing offline depth). Separate from the queue: queue items become
 * crawl work; warnings become manifest.warnings entries.
 */
interface WarningCollector
{
    public const ORIGIN_LEFTOVER = 'leftover_origin';

    public function record(string $category, string $message): void;
}
