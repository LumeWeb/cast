<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Immutable caps for sitemap ingestion, matching the discovery plan: body 5
 * MiB, nesting depth 5, and total URL count 250,000. Kept as plain ints so the
 * filter stays pure and XML-parsing-free.
 */
final class SitemapLimits
{
    public const DEFAULT_MAX_LOCS = 250_000;
    public const DEFAULT_MAX_DEPTH = 5;
    public const DEFAULT_MAX_BODY_BYTES = 5 * 1024 * 1024;

    public function __construct(
        public readonly int $maxLocs = self::DEFAULT_MAX_LOCS,
        public readonly int $maxDepth = self::DEFAULT_MAX_DEPTH,
        public readonly int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
    ) {
    }
}
