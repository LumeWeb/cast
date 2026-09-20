<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

use LumeWeb\Cast\Export\WorkItem;

/**
 * Result of converting one raw URL reference for the current destination mode:
 * the rewritten value plus (when the URL was an in-origin URL) the WorkItem the
 * caller must queue. unchanged() marks values left exactly as written (skipped
 * non-web refs and external URLs) that must not be queued.
 */
final class UrlConversion
{
    private function __construct(
        private readonly string $rewritten,
        private readonly ?WorkItem $queued,
        private readonly bool $unchanged,
    ) {
    }

    public static function changed(string $rewritten, ?WorkItem $queued = null): self
    {
        return new self($rewritten, $queued, false);
    }

    public static function leftAsIs(string $value): self
    {
        return new self($value, null, true);
    }

    public function rewritten(): string
    {
        return $this->rewritten;
    }

    public function queued(): ?WorkItem
    {
        return $this->queued;
    }

    public function unchanged(): bool
    {
        return $this->unchanged;
    }
}
