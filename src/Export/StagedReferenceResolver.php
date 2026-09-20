<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Resolves a raw reference as written in a staged document into the staged
 * output file it denotes — pure relative/root-relative/same-origin math with
 * RFC 3986 dot-segment removal, plus trailing-slash and extensionless
 * index.html disambiguation against the tree. Returns null when the reference
 * is not a local resource (fragment, query-only, non-web scheme, or an
 * off-origin URL), which the local-reference validator skips rather than
 * reports as broken.
 */
final class StagedReferenceResolver
{
    private const NON_WEB_SCHEMES = ['data', 'mailto', 'tel', 'javascript', 'about', 'blob'];

    public function __construct(
        private readonly ArtifactTree $tree,
        private readonly Origin $origin,
    ) {
    }

    public function resolve(string $reference, string $documentOutputPath): ?string
    {
        $reference = trim($reference);
        if ($reference === '' || str_starts_with($reference, '#')) {
            return null;
        }

        // Local references never need query/fragment for existence checks; a
        // query-only reference ('?s=1') collapses to '' and is not local.
        $reference = preg_split('/[?#]/', $reference, 2)[0] ?? '';
        if ($reference === '') {
            return null;
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $reference, $match) === 1) {
            $scheme = strtolower(rtrim($match[0], ':'));

            if (in_array($scheme, self::NON_WEB_SCHEMES, true)) {
                return null;
            }

            if (!in_array($scheme, ['http', 'https'], true)) {
                return null;
            }

            return $this->resolveWebUrl($reference);
        }

        if (str_starts_with($reference, '//')) {
            return $this->resolveWebUrl('http:' . $reference);
        }

        if (str_starts_with($reference, '/')) {
            return $this->candidate($this->removeDotSegments(ArtifactPath::normalize($reference)));
        }

        $baseDir = dirname($documentOutputPath);
        $joined = ($baseDir === '.' || $baseDir === '') ? $reference : $baseDir . '/' . $reference;

        return $this->candidate($this->removeDotSegments($joined));
    }

    private function resolveWebUrl(string $absolute): ?string
    {
        $parts = parse_url($absolute);
        if ($parts === false || !isset($parts['host'])) {
            return null;
        }

        if (strtolower($parts['host']) !== $this->origin->host()) {
            return null;
        }

        $path = $parts['path'] ?? '';

        return $this->candidate($this->removeDotSegments(ArtifactPath::normalize($path)));
    }

    private function candidate(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        if (str_ends_with($path, '/')) {
            return $this->tree->exists($path . 'index.html') ? $path . 'index.html' : null;
        }

        if (pathinfo($path, PATHINFO_EXTENSION) === '' && $this->tree->exists($path . '/index.html')) {
            return $path . '/index.html';
        }

        return $path;
    }

    private function removeDotSegments(string $path): string
    {
        $segments = explode('/', $path);
        $out = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($out !== []) {
                    array_pop($out);
                }
                continue;
            }
            $out[] = $segment;
        }

        return implode('/', $out);
    }
}
