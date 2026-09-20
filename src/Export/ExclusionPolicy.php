<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Typed enqueue exclusions evaluated on a canonical URL before insertion.
 *
 * Every rule matches a precise shape (path segment, exact basename, or a
 * named query parameter) — never a loose substring, so `feed` never rejects
 * `feedback` and `wp-admin` never rejects `/wp-adminson/`.
 */
final class ExclusionPolicy
{
    public function __construct(
        private readonly ExclusionOptions $options = new ExclusionOptions(),
    ) {
    }

    public function isExcluded(Url $url): bool
    {
        $path = $url->path();

        // Glob/pattern pseudo-URLs (`/wp-*.php`, `/wp-admin/*`) never name one
        // real resource and must never reach the queue. This is defense-in-depth
        // on the discovery path: UrlCandidateNormalizer already refuses a
        // literal `*` with InvalidUrl, and the percent-encoded `%2A` stays
        // allowed on both sides.
        if (str_contains($path, '*') || str_contains($url->query(), '*')) {
            return true;
        }

        if ($this->isAdminPath($path)) {
            return true;
        }
        if ($this->isAdminFile($path, 'wp-login.php') || $this->isAdminFile($path, 'xmlrpc.php')) {
            return true;
        }
        if ($this->options->excludeRests && $this->isRestUrl($url)) {
            return true;
        }
        if ($this->options->excludeFeeds && $this->isFeedUrl($url)) {
            return true;
        }
        if ($this->hasAnyQueryParam($url, ['preview', 'customize_changeset_uuid', 'wp_customize', 'customize_theme', '_wpnonce', 'nonce', 'add-to-cart', 'wc-ajax'])) {
            return true;
        }

        return false;
    }

    private function isAdminPath(string $path): bool
    {
        return $path === '/wp-admin' || str_starts_with($path, '/wp-admin/');
    }

    private function isAdminFile(string $path, string $basename): bool
    {
        return strtolower(basename($path)) === $basename;
    }

    private function isRestUrl(Url $url): bool
    {
        return $url->path() === '/wp-json' || str_starts_with($url->path(), '/wp-json/')
            || $this->hasQueryParam($url, 'rest_route');
    }

    private function isFeedUrl(Url $url): bool
    {
        if ($this->hasQueryParam($url, 'feed')) {
            return true;
        }

        $segments = $this->pathSegments($url->path());
        if ($segments === []) {
            return false;
        }

        $count = count($segments);
        if ($segments[$count - 1] === 'feed') {
            return true;
        }

        // /feed/atom/, /feed/rss2/, /feed/rss/, /feed/rdf/ — a typed two-segment
        // shape, never "anything containing feed".
        return in_array($segments[$count - 1], ['atom', 'rss', 'rss2', 'rdf'], true)
            && $count >= 2
            && $segments[$count - 2] === 'feed';
    }

    /**
     * @return list<string>
     */
    private function pathSegments(string $path): array
    {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

        return array_map('strtolower', $segments);
    }

    /**
     * @param list<string> $names
     */
    private function hasAnyQueryParam(Url $url, array $names): bool
    {
        foreach ($names as $name) {
            if ($this->hasQueryParam($url, $name)) {
                return true;
            }
        }

        return false;
    }

    private function hasQueryParam(Url $url, string $name): bool
    {
        if ($url->query() === '') {
            return false;
        }

        $name = strtolower($name);
        foreach (explode('&', $url->query()) as $pair) {
            $key = strtolower((string) strstr($pair, '=', true));
            if ($key === $name) {
                return true;
            }
        }

        return false;
    }
}
