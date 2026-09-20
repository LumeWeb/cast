<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * The typed upload request the transport adapters must satisfy: the artifact
 * path on disk, the archive flag (always true in v1 — the server unpacks the
 * ZIP), the upload name, and the size/route decided by the router.
 */
final class UploadSpec
{
    public function __construct(
        public readonly string $artifactPath,
        public readonly string $name,
        public readonly bool $archive,
        public readonly int $sizeBytes,
        public readonly UploadRoute $route,
    ) {
    }
}
