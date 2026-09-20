<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Lets only local, in-cap sitemap locations through.
 *
 * No XML parsing happens here — locations arrive as already-collected strings,
 * and the depth/body metadata rides on SitemapDocument. The filter guarantees:
 * same-origin only, optional body-size and nesting-depth caps, and a hard
 * URL-count bound that stops consuming the source the moment the cap is met,
 * so ingestion can never grow without limit.
 */
final class SitemapLocFilter
{
    public function __construct(
        private readonly SitemapLimits $limits = new SitemapLimits(),
        private readonly UrlCanonicalizer $canonicalizer = new UrlCanonicalizer(),
        private readonly OriginPolicy $originPolicy = new OriginPolicy(),
    ) {
    }

    public function isLocal(string $loc, Origin $origin): bool
    {
        try {
            $url = $this->canonicalizer->canonicalize($loc);
        } catch (InvalidUrl) {
            return false;
        }

        return $this->originPolicy->isAllowed($url, $origin);
    }

    public function accepts(SitemapDocument $doc, Origin $origin): bool
    {
        if ($doc->depth > $this->limits->maxDepth) {
            return false;
        }
        if ($doc->bytes !== null && $doc->bytes > $this->limits->maxBodyBytes) {
            return false;
        }

        return $this->isLocal($doc->loc, $origin);
    }

    /**
     * Yield accepted documents in order, stopping after maxLocs accepted URLs
     * and never pulling from the source past that point.
     *
     * @param iterable<SitemapDocument> $documents
     *
     * @return iterable<SitemapDocument>
     */
    public function filterDocuments(iterable $documents, Origin $origin): iterable
    {
        $iterator = $this->toIterator($documents);
        $accepted = 0;

        while ($iterator->valid() && $accepted < $this->limits->maxLocs) {
            $doc = $iterator->current();

            if ($this->accepts($doc, $origin)) {
                yield $doc;
                ++$accepted;

                if ($accepted >= $this->limits->maxLocs) {
                    break;
                }
            }

            $iterator->next();
        }
    }

    /**
     * @param iterable<SitemapDocument> $iterable
     *
     * @return \Iterator<SitemapDocument>
     */
    private function toIterator(iterable $iterable): \Iterator
    {
        if (is_array($iterable)) {
            return new \ArrayIterator($iterable);
        }
        if ($iterable instanceof \Iterator) {
            return $iterable;
        }

        return new \IteratorIterator($iterable);
    }
}
