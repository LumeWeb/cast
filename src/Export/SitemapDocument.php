<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Metadata about one sitemap document candidate: its <loc> URL string, how
 * deep it sits in the sitemap index tree (index = 0), and the byte size of its
 * body when known. The filter enforces the depth and body caps against this
 * data without ever parsing XML.
 */
final class SitemapDocument
{
    public function __construct(
        public readonly string $loc,
        public readonly int $depth = 0,
        public readonly ?int $bytes = null,
    ) {
    }
}
