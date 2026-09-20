<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * OutputFileSystem over a real work directory. put() writes to a temporary
 * sibling and atomically renames it over the destination so a crash never
 * leaves a half-written artifact; dot segments are refused so no accepted URL
 * can ever write outside the work directory.
 */
final class LocalOutputFileSystem implements OutputFileSystem
{
    public function __construct(
        private readonly string $workDir,
    ) {
    }

    public function put(string $relativePath, string $contents): void
    {
        $this->assertNoTraversal($relativePath);
        $root = $this->root();

        $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($absolute);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create output directory %s', $directory));
        }

        $resolved = realpath($directory);
        if ($resolved === false || !$this->isInside($resolved, $root)) {
            throw new \RuntimeException(sprintf('Output directory %s escapes the work directory', $directory));
        }

        $tmp = tempnam($resolved, '.cast-');
        if ($tmp === false || file_put_contents($tmp, $contents) === false) {
            if (is_string($tmp)) {
                @unlink($tmp);
            }
            throw new \RuntimeException(sprintf('Cannot stage output for %s', $relativePath));
        }

        $destination = $resolved . DIRECTORY_SEPARATOR . basename($absolute);
        if (!@rename($tmp, $destination)) {
            // Cross-device/Windows fallback: copy then remove the staged file.
            if (!copy($tmp, $destination)) {
                @unlink($tmp);
                throw new \RuntimeException(sprintf('Cannot commit output for %s', $relativePath));
            }
            @unlink($tmp);
        }
    }

    public function delete(string $relativePath): void
    {
        $absolute = $this->locate($relativePath);
        if ($absolute !== null && is_file($absolute)) {
            @unlink($absolute);
        }
    }

    public function exists(string $relativePath): bool
    {
        $absolute = $this->locate($relativePath);

        return $absolute !== null && is_file($absolute);
    }

    /**
     * The absolute path for an already-validated relative path, or null when
     * the parent directory cannot exist (e.g. it was never created). Kept
     * separate from put() so delete()/exists() never need to create anything.
     */
    private function locate(string $relativePath): ?string
    {
        if ($this->containsDotSegments($relativePath)) {
            return null;
        }
        $root = realpath($this->workDir);
        if ($root === false) {
            return null;
        }

        $directory = realpath(dirname($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath)));
        if ($directory === false || !$this->isInside($directory, $root)) {
            return null;
        }

        return $directory . DIRECTORY_SEPARATOR . basename($relativePath);
    }

    private function assertNoTraversal(string $relativePath): void
    {
        if ($this->containsDotSegments($relativePath)) {
            throw new \InvalidArgumentException(sprintf('Output path %s escapes the work directory', $relativePath));
        }
    }

    private function containsDotSegments(string $relativePath): bool
    {
        foreach (explode('/', str_replace('\\', '/', $relativePath)) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }

    private function root(): string
    {
        $root = realpath($this->workDir);
        if ($root === false) {
            throw new \RuntimeException(sprintf('Output work directory %s does not exist', $this->workDir));
        }

        return $root;
    }

    private function isInside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/') . DIRECTORY_SEPARATOR);
    }
}
