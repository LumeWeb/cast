<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * One enumerated artifact ZIP under the cast-exports jail: its basename, its
 * canonical realpath and the unix modified-at instant retention decisions are
 * made against. The path is the realpath the store resolved, so downstream
 * deletion re-validates the exact canonical location.
 */
final class ArtifactFile
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly int $modifiedAt,
    ) {
    }
}
