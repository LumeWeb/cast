<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * The thing being published: the exported ZIP on disk, its upload name, the
 * portal target type to stamp on the website, the site label (hostname) used
 * for the website label and IPNS key name, and the byte size that drives route
 * selection.
 */
final class Artifact
{
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly string $targetType,
        public readonly string $label,
        public readonly int $sizeBytes,
    ) {
    }
}
