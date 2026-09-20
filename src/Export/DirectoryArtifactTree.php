<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * ArtifactTree over a real jailed work directory. paths() walks the tree and
 * yields sorted forward-slash relative names; symlinks are listed (so the pack
 * jail can reject escapes on their realpath) while directories are not.
 */
final class DirectoryArtifactTree implements ArtifactTree
{
    public function __construct(private readonly string $root)
    {
    }

    public function paths(): array
    {
        $root = realpath($this->root);
        if ($root === false || !is_dir($root)) {
            return [];
        }

        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $entry) {
            if (!$entry->isFile() && !$entry->isLink()) {
                continue;
            }
            $paths[] = $this->relative($root, $entry->getPathname());
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    public function exists(string $relativePath): bool
    {
        return $this->path($relativePath) !== null;
    }

    public function readText(string $relativePath): ?string
    {
        $path = $this->path($relativePath);
        if ($path === null || !is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public function isTextLike(string $relativePath): bool
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        return in_array($extension, ['html', 'htm', 'css', 'js', 'json', 'xml', 'txt', 'svg'], true);
    }

    private function path(string $relativePath): ?string
    {
        $root = realpath($this->root);
        if ($root === false) {
            return null;
        }
        if (ArtifactPath::violation($relativePath) !== null) {
            return null;
        }

        $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $real = realpath($candidate);
        if ($real === false || !$this->isInside($real, $root)) {
            return null;
        }

        return $real;
    }

    private function relative(string $root, string $path): string
    {
        return ArtifactPath::normalize(str_replace($root . DIRECTORY_SEPARATOR, '', $path));
    }

    private function isInside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/') . DIRECTORY_SEPARATOR);
    }
}
