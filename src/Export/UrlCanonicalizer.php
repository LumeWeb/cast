<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Turns a raw URL into a canonical Url or throws InvalidUrl before it can
 * reach the queue. Implements the identity rules that hold independent of
 * WordPress: http/https only, lowercase scheme and host, effective-port
 * normalization, fragment stripping, sorted page query strings, and collapse
 * of over-encoded percent escapes (`%25xx` -> `%xx`).
 */
final class UrlCanonicalizer
{
    public function canonicalize(string $url): Url
    {
        if (trim($url) === '') {
            throw $this->reject(UrlRejection::Empty, $url, 'URL is empty');
        }

        if (str_contains($url, "\0")) {
            throw $this->reject(UrlRejection::NulByte, $url, 'URL contains a NUL byte');
        }

        if (preg_match('/[\x00-\x1F]/', $url) === 1) {
            throw $this->reject(UrlRejection::ControlCharacter, $url, 'URL contains a C0 control character');
        }

        if (str_contains($url, '\\')) {
            throw $this->reject(UrlRejection::Backslash, $url, 'URL contains a backslash');
        }

        $parts = parse_url($url);
        if ($parts === false) {
            throw $this->reject(UrlRejection::Malformed, $url, 'URL cannot be parsed');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme === '') {
            throw $this->reject(UrlRejection::Relative, $url, 'URL has no scheme; relative URLs resolve against their document later');
        }

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw $this->reject(UrlRejection::NonHttpScheme, $url, "scheme '{$scheme}' is not http/https");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw $this->reject(UrlRejection::UserInfo, $url, 'URL contains userinfo (user:pass@)');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            throw $this->reject(UrlRejection::Malformed, $url, 'URL has no host');
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && $port === ($scheme === 'https' ? 443 : 80)) {
            // The scheme-default port must not participate in identity, so
            // http:80 and https:443 collapse onto their scheme-less forms.
            $port = null;
        }

        $path = $this->collapseOverEncoded((string) ($parts['path'] ?? ''));
        if ($path === '') {
            $path = '/';
        }

        $query = $this->sortQuery($this->collapseOverEncoded((string) ($parts['query'] ?? '')));

        return new Url($scheme, $host, $port, $path, $query);
    }

    /**
     * Sorts query pairs lexicographically so parameter-order variants of the
     * same page share one identity and one storage hash.
     */
    private function sortQuery(string $query): string
    {
        $pairs = array_values(array_filter(explode('&', $query), static fn (string $p): bool => $p !== ''));

        sort($pairs, SORT_STRING);

        return implode('&', $pairs);
    }

    /**
     * Collapses over-encoded percent escapes (`%25xx` -> `%xx`) so URLs that
     * decode to the same resource share one identity. Applied to the path and
     * query only; fragments are already dropped and the authority is never
     * touched. A lone literal `%` (`%25` not followed by hex) and every other
     * escape (`%20`, `%2f`, ...) are left exactly as written — nothing is
     * un-encoded to a raw byte, so `%2e%2e`/separator forms the jail rejects
     * stay percent-encoded. Repeats until stable; each pass strictly shortens
     * the component so iteration always terminates (a `%25252520` nests three
     * levels deep).
     */
    private function collapseOverEncoded(string $component): string
    {
        do {
            $previous = $component;
            $component = (string) preg_replace('/%25([0-9A-Fa-f]{2})/', '%$1', $component);
        } while ($component !== $previous);

        return $component;
    }

    private function reject(UrlRejection $rejection, string $url, string $reason): InvalidUrl
    {
        return new InvalidUrl($rejection, sprintf('Cannot canonicalize URL %s: %s', $url, $reason));
    }
}
