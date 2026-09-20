<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The filesystem boundary capture validates against: accepted bodies are
 * written atomically to their deterministic relative path, and rejected
 * outcomes delete any stale incremental output at that path. Persistence and
 * WordPress never appear here.
 */
interface OutputFileSystem
{
    public function put(string $relativePath, string $contents): void;

    public function delete(string $relativePath): void;

    public function exists(string $relativePath): bool;
}
