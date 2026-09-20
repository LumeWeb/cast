<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Resolves an HTTP Location header against the canonical request URL so a
 * redirect target is always an absolute, canonical Url before the origin check
 * or the output-path comparison runs. Handles absolute, root-relative,
 * protocol-relative, query-only and fragment-only targets and collapses dot
 * segments exactly once.
 */
final class LocationResolver
{
    public function __construct(
        private readonly UrlCanonicalizer $canonicalizer = new UrlCanonicalizer(),
    ) {
    }

    public function resolve(string $location, Url $base): Url
    {
        $location = trim($location);
        if ($location === '') {
            throw new InvalidUrl(UrlRejection::Empty, 'Cannot resolve an empty redirect Location');
        }

        if (is_string(parse_url($location, PHP_URL_SCHEME))) {
            return $this->canonicalizer->canonicalize($location);
        }

        if (str_starts_with($location, '//')) {
            return $this->canonicalizer->canonicalize($base->scheme() . ':' . $location);
        }

        if (str_starts_with($location, '?')) {
            return $this->canonicalizer->canonicalize($base->base() . $location);
        }

        if (str_starts_with($location, '#')) {
            return $this->canonicalizer->canonicalize($base->base());
        }

        if (str_starts_with($location, '/')) {
            return $this->canonicalizer->canonicalize(
                $base->scheme() . '://' . $base->authority() . $this->removeDotSegments($location)
            );
        }

        $merged = $this->baseDirectory($base->path()) . $location;

        return $this->canonicalizer->canonicalize(
            $base->scheme() . '://' . $base->authority() . $this->removeDotSegments($merged)
        );
    }

    /**
     * The directory a relative reference resolves against: the whole path when
     * it already ends in a slash, otherwise everything up to the last slash.
     */
    private function baseDirectory(string $path): string
    {
        if (str_ends_with($path, '/')) {
            return $path;
        }

        $position = strrpos($path, '/');

        return $position === false ? '/' : substr($path, 0, $position + 1);
    }

    /**
     * Removes '.' and '..' segments per RFC 3986; '..' above the root clamps
     * at root instead of escaping it.
     */
    private function removeDotSegments(string $path): string
    {
        $leadingSlash = str_starts_with($path, '/');
        $trailingSlash = str_ends_with($path, '/') && $path !== '/';
        $stack = [];

        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($stack !== []) {
                    array_pop($stack);
                }
                continue;
            }
            $stack[] = $segment;
        }

        $joined = implode('/', $stack);
        if ($leadingSlash) {
            $joined = '/' . $joined;
        }
        if ($trailingSlash) {
            $joined .= '/';
        }

        return $joined;
    }
}
