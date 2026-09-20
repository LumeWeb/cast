<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Default DiskAssetSource mapping a canonical URL path directly under a jail
 * root (the WordPress tree in later slices), with a realpath containment
 * check and explicit dot-segment rejection so no local copy can ever read
 * outside the jail. A missing or jailed-out file reads as null so the caller
 * falls back to HTTP.
 */
final class JailedDiskAssetSource implements DiskAssetSource
{
    public function __construct(
        private readonly string $root,
    ) {
    }

    public function read(WorkItem $item): ?CaptureBody
    {
        $relative = ltrim($item->url()->path(), '/');
        if ($relative === '' || $this->containsDotSegments($relative)) {
            return null;
        }

        $root = realpath($this->root);
        if ($root === false) {
            return null;
        }

        $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $real = realpath($candidate);
        if ($real === false || !is_file($real) || !$this->isInside($real, $root)) {
            return null;
        }

        $handle = fopen($real, 'rb');
        if ($handle === false) {
            return null;
        }

        return CaptureBody::fromStream($handle);
    }

    private function containsDotSegments(string $relative): bool
    {
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }

    private function isInside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/') . DIRECTORY_SEPARATOR);
    }
}
