<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\Url;

/**
 * Immutable per-document state for one rewrite pass.
 *
 * document() is the URL the current content is resolved against — the page URL
 * for HTML/JS/text, the CSS file URL for a stylesheet, the XML/JSON file URL
 * for feeds and config. origin() is the exact WordPress origin used by the
 * queue check; queue() sinks every discovered in-origin WorkItem so the crawler
 * reaches its fixed point.
 */
final class RewriteContext
{
    public function __construct(
        private readonly Url $document,
        private readonly Origin $origin,
        private readonly QueueCollector $queue,
    ) {
    }

    public function document(): Url
    {
        return $this->document;
    }

    public function origin(): Origin
    {
        return $this->origin;
    }

    public function queue(): QueueCollector
    {
        return $this->queue;
    }
}
