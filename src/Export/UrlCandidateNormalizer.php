<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The single check a discovered URL candidate must pass before it can ever be
 * inserted: canonicalize it, then reject anything outside the exact WordPress
 * origin. Seeders call normalizeUrl() and feed the result to the typed
 * exclusion policy, then hand the surviving Url to workItem().
 */
final class UrlCandidateNormalizer
{
    public function __construct(
        private readonly UrlCanonicalizer $canonicalizer = new UrlCanonicalizer(),
        private readonly OriginPolicy $originPolicy = new OriginPolicy(),
        private readonly WorkItemFactory $workItemFactory = new WorkItemFactory(),
    ) {
    }

    /**
     * @throws InvalidUrl if the raw value cannot become a canonical URL.
     * @throws OffOriginUrl if the canonical URL is outside the WordPress origin.
     */
    public function normalizeUrl(string $raw, Origin $origin): Url
    {
        $url = $this->canonicalizer->canonicalize($raw);
        $this->assertNoWildcard($url, $raw);
        $this->originPolicy->assertAllowed($url, $origin);

        return $url;
    }

    /**
     * The single check that refuses a glob/pattern pseudo-URL before it can
     * ever be inserted: a literal `*` in the canonical path or query means the
     * value is a matching pattern (`/wp-*.php`, `/wp-admin/*`), never one real
     * resource, and a `*`-URL would collapse many URLs onto one identity and
     * one storage hash. WordPress URLs never use a raw `*`; the percent-encoded
     * `%2A` is not affected and stays allowed. Throws the same InvalidUrl the
     * canonicalizer uses, so discovery's enqueue drops it without counting.
     */
    private function assertNoWildcard(Url $url, string $raw): void
    {
        if (str_contains($url->path(), '*') || str_contains($url->query(), '*')) {
            throw new InvalidUrl(
                UrlRejection::Wildcard,
                sprintf('Cannot enqueue URL %s: it contains a literal wildcard (*): not one real resource.', $raw),
            );
        }
    }

    public function workItem(Url $url): WorkItem
    {
        return $this->workItemFactory->fromUrl($url);
    }
}
