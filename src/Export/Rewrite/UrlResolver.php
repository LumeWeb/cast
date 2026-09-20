<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

use LumeWeb\Cast\Export\Url;

/**
 * RFC 3986-ish resolution of a raw reference against its source document URL.
 *
 * Fragment-only references and non-web schemes (data/javascript/mailto/tel and
 * anything unknown) are skipped unchanged. Protocol-relative refs inherit the
 * document scheme; root-relative and relative refs are joined against the
 * document origin/directory with RFC 3986 dot-segment removal. The result
 * never carries the fragment; query strings survive for page identity.
 */
final class UrlResolver
{
    private const NON_WEB_SCHEMES = ['data', 'javascript', 'mailto', 'tel'];

    public function resolve(string $raw, Url $document): UrlResolution
    {
        $raw = trim($raw);

        if ($raw === '' || str_starts_with($raw, '#')) {
            return UrlResolution::skip($raw);
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $raw, $match) === 1) {
            $scheme = strtolower(rtrim($match[0], ':'));

            if (\in_array($scheme, self::NON_WEB_SCHEMES, true)) {
                return UrlResolution::skip($raw);
            }

            if (\in_array($scheme, ['http', 'https'], true)) {
                return UrlResolution::web($this->normalizeAuthority($raw));
            }

            // Any other scheme (blob:, chrome:, ...): leave unchanged.
            return UrlResolution::skip($raw);
        }

        if (str_starts_with($raw, '//')) {
            return UrlResolution::web($this->normalizeAuthority($document->scheme() . ':' . $raw));
        }

        return UrlResolution::web($this->merge($raw, $document));
    }

    private function merge(string $raw, Url $document): string
    {
        [$rawPath, $rawQuery] = $this->splitPathQuery($raw);
        $basePath = $document->path();

        if ($rawPath === '' && $rawQuery !== null) {
            $path = $basePath;
            $query = $rawQuery;
        } elseif ($rawPath === '') {
            $path = $basePath;
            $query = $rawQuery ?? $document->query();
        } elseif (str_starts_with($rawPath, '/')) {
            $path = $this->removeDotSegments($rawPath);
            $query = $rawQuery ?? '';
        } else {
            $lastSlash = strrpos($basePath, '/');
            $directory = $lastSlash === false ? '/' : substr($basePath, 0, $lastSlash + 1);
            $path = $this->removeDotSegments($directory . $rawPath);
            $query = $rawQuery ?? '';
        }

        return $document->scheme()
            . '://'
            . $document->authority()
            . $path
            . ($query !== '' ? '?' . $query : '');
    }

    /**
     * Lowercase scheme/host so the canonicalizer sees a clean absolute URL.
     */
    private function normalizeAuthority(string $absolute): string
    {
        $parts = parse_url($absolute);
        if ($parts === false || !isset($parts['host'])) {
            return $absolute;
        }

        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $scheme = strtolower($parts['scheme'] ?? 'http');
        $host = strtolower($parts['host']);

        return $scheme . '://' . $host . $port . $path . $query;
    }

    /**
     * @return array{0: string, 1: string|null} [path (no query/fragment), query|null]
     */
    private function splitPathQuery(string $raw): array
    {
        $fragmentAt = strpos($raw, '#');
        if ($fragmentAt !== false) {
            $raw = substr($raw, 0, $fragmentAt);
        }

        $queryAt = strpos($raw, '?');
        if ($queryAt === false) {
            return [$raw, null];
        }

        return [substr($raw, 0, $queryAt), substr($raw, $queryAt + 1)];
    }

    private function removeDotSegments(string $path): string
    {
        $output = '';
        $input = $path;

        while ($input !== '') {
            if (str_starts_with($input, '../')) {
                $input = substr($input, 3);
            } elseif (str_starts_with($input, './')) {
                $input = substr($input, 2);
            } elseif (str_starts_with($input, '/./')) {
                $input = '/' . substr($input, 3);
            } elseif ($input === '/.') {
                $input = '/';
            } elseif (str_starts_with($input, '/../')) {
                $input = '/' . substr($input, 4);
                $output = $this->removeLastSegment($output);
            } elseif ($input === '/..') {
                $input = '/';
                $output = $this->removeLastSegment($output);
            } elseif ($input === '.' || $input === '..') {
                $input = '';
            } else {
                $segmentEnd = strpos($input, '/', 1);
                if ($segmentEnd === false) {
                    $output .= $input;
                    $input = '';
                } else {
                    $output .= substr($input, 0, $segmentEnd);
                    $input = substr($input, $segmentEnd);
                }
            }
        }

        return $output;
    }

    private function removeLastSegment(string $path): string
    {
        $pos = strrpos($path, '/');
        if ($pos === false) {
            return '';
        }

        return substr($path, 0, $pos);
    }
}
