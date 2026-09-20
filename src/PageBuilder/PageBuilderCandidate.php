<?php

declare(strict_types=1);

namespace LumeWeb\Cast\PageBuilder;

/**
 * Immutable metadata for a single curated page-builder candidate.
 *
 * Values are deliberately descriptive rather than numeric: lock-in,
 * performance, and cost notes are honest prose, and performance notes never
 * assert measured speedups. The editor target is only populated once verified;
 * unverified candidates keep it null.
 */
final readonly class PageBuilderCandidate
{
    /**
     * @param string|null $slug WordPress.org plugin slug, or null when the
     *                          candidate is not a plugin (the native editor).
     * @param PageBuilderSource|null $source Distribution source, or null when the
     *                                       candidate is not a plugin. The only
     *                                       source the installer can drive is
     *                                       WordPress.org; any other source is
     *                                       data left for future handling.
     * @param string|null $editorTarget Verified editor surface ('block',
     *                                  'classic', ...) or null until verified.
     */
    public function __construct(
        public ?string $slug,
        public string $label,
        public PageBuilderKind $kind,
        public bool $isCore,
        public bool $isFree,
        public bool $installable,
        public bool $recommended,
        public string $lockInNote,
        public string $performanceNote,
        public string $costNote,
        public ?string $editorTarget,
        public ?PageBuilderSource $source = null,
    ) {
    }
}
