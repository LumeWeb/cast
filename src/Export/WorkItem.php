<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Immutable canonical work item: the identity, its fixed-length hash, the
 * classified kind, and the deterministic output path. Discovery and capture
 * read these values; nothing here touches persistence.
 */
final class WorkItem
{
    public function __construct(
        private readonly Url $url,
        private readonly WorkItemKind $kind,
        private readonly string $identity,
        private readonly string $urlHash,
        private readonly string $outputPath,
    ) {
    }

    public function url(): Url
    {
        return $this->url;
    }

    public function kind(): WorkItemKind
    {
        return $this->kind;
    }

    /**
     * Canonical URL used for deduplication: pages keep their sorted query,
     * assets and text files do not.
     */
    public function identity(): string
    {
        return $this->identity;
    }

    /**
     * md5 of the full identity; always 32 characters, so long query strings
     * never truncate or collide.
     */
    public function urlHash(): string
    {
        return $this->urlHash;
    }

    public function outputPath(): string
    {
        return $this->outputPath;
    }
}
