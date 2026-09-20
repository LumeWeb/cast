<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Pure archive/output-path policy: deterministic normalization to forward
 * slash relative paths, plus machine-readable rejection of anything that could
 * escape the work-tree jail (absolute paths, dot segments, NUL/control bytes).
 * Used by the pre-pack validation and the ZIP packager's path jail.
 */
final class ArtifactPath
{
    /**
     * Normalize to a forward-slash relative path: backslashes fold to '/',
     * a leading '/' or './' is trimmed, duplicate separators collapse and a
     * trailing '/' is dropped. Dot segments are deliberately NOT resolved here
     * so the jail can still reject them after normalization.
     */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('~/{2,}~', '/', $path) ?? $path;
        $path = preg_replace('~^(?:\./)+~', '', $path) ?? $path;
        $path = ltrim($path, '/');
        $path = rtrim($path, '/');

        return $path;
    }

    /**
     * Machine-readable rejection reason for an unsafe relative output path, or
     * null when the normalized path may be written into the work tree.
     */
    public static function violation(string $relativePath): ?string
    {
        if ($relativePath === '') {
            return 'empty';
        }

        if (str_contains($relativePath, "\0")) {
            return 'nul_byte';
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $relativePath) === 1) {
            return 'control_character';
        }

        $normalized = self::normalize($relativePath);

        if ($normalized === '') {
            return 'empty';
        }

        if (self::isAbsolute($relativePath)) {
            return 'absolute';
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return 'dot_segment';
            }
        }

        return null;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string> normalized paths that appear more than once, sorted
     */
    public static function duplicates(array $paths): array
    {
        $seen = [];
        foreach ($paths as $path) {
            if ($path === '') {
                continue;
            }
            $normalized = self::normalize($path);
            if ($normalized === '') {
                continue;
            }
            $seen[$normalized] = ($seen[$normalized] ?? 0) + 1;
        }

        $duplicates = [];
        foreach ($seen as $path => $count) {
            if ($count > 1) {
                $duplicates[] = $path;
            }
        }
        sort($duplicates);

        return $duplicates;
    }

    private static function isAbsolute(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return str_starts_with($normalized, '/')
            || preg_match('~^[a-zA-Z]:/~', $normalized) === 1;
    }
}
