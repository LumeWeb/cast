<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The path jail for local asset resolution. The raw URL-encoded relative
 * path is validated before and after a single rawurldecode (rejecting encoded
 * separators/dot-dot/NUL/control, then decoded NUL/backslash/C0/dot segments),
 * then passed through a most-specific realpath containment check so a symlink
 * or traversal can never read outside the jail. A missing, escaped or unsafe
 * file resolves to null so the caller falls back to HTTP.
 */
final class JailedPath
{
    public static function resolve(string $relative, string $root): ?string
    {
        $relative = ltrim($relative, '/');
        if ($relative === '' || !self::encodedIsSafe($relative)) {
            return null;
        }

        $decoded = rawurldecode($relative);
        if (!self::decodedIsSafe($decoded)) {
            return null;
        }

        $rootReal = realpath($root);
        if ($rootReal === false || !is_dir($rootReal)) {
            return null;
        }

        $candidate = $rootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $decoded);
        $real = realpath($candidate);
        if ($real === false || !is_file($real) || !self::isInside($real, $rootReal)) {
            return null;
        }

        return $real;
    }

    private static function encodedIsSafe(string $relative): bool
    {
        if (preg_match('/%(?:2f|5c)/i', $relative) === 1) {
            return false; // Encoded '/' or '\' separator.
        }

        if (preg_match('/%00|[%](?:0[1-9a-f]|1[0-9a-f]|7f)/i', $relative) === 1) {
            return false; // Encoded NUL or C0/control byte.
        }

        if (preg_match('/(?:%2e%2e|%252e%252e)/i', $relative) === 1) {
            return false; // Encoded (or double-encoded) '..'.
        }

        return true;
    }

    private static function decodedIsSafe(string $decoded): bool
    {
        if ($decoded === '' || str_contains($decoded, "\0") || str_contains($decoded, '\\')) {
            return false;
        }

        $length = strlen($decoded);
        for ($i = 0; $i < $length; $i++) {
            $ord = ord($decoded[$i]);
            if ($ord < 0x20 || $ord === 0x7f) {
                return false;
            }
        }

        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private static function isInside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/') . DIRECTORY_SEPARATOR);
    }
}
