<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

use LumeWeb\Cast\Export\InvalidUrl;
use LumeWeb\Cast\Export\OffOriginUrl;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\OriginPolicy;
use LumeWeb\Cast\Export\Url;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemFactory;

/**
 * The convert_url step for the offline destination.
 *
 * Resolve a raw reference against its source document URL (RFC 3986-ish),
 * preserve fragments and non-web schemes unchanged, queue every in-origin URL
 * through the ORIGINAL origin policy, and rewrite in-origin URLs to a './'
 * relative path from the page's own output file. External URLs are left
 * unchanged and are never queued.
 */
final class UrlConverter
{
    public function __construct(
        private readonly UrlResolver $urlResolver = new UrlResolver(),
        private readonly UrlCanonicalizer $canonicalizer = new UrlCanonicalizer(),
        private readonly OriginPolicy $originPolicy = new OriginPolicy(),
        private readonly WorkItemFactory $workItemFactory = new WorkItemFactory(),
        private readonly OfflinePathCalculator $pathCalculator = new OfflinePathCalculator(),
    ) {
    }

    public function convert(string $raw, Url $document, Origin $origin): UrlConversion
    {
        [$withoutFragment, $fragment] = $this->splitFragment($raw);

        $resolution = $this->urlResolver->resolve($withoutFragment, $document);
        if ($resolution->isSkip()) {
            return UrlConversion::leftAsIs($raw);
        }

        try {
            $url = $this->canonicalizer->canonicalize($resolution->value());
        } catch (InvalidUrl) {
            return UrlConversion::leftAsIs($raw);
        }

        try {
            $this->originPolicy->assertAllowed($url, $origin);
        } catch (OffOriginUrl) {
            return UrlConversion::leftAsIs($raw);
        }

        $item = $this->workItemFactory->fromUrl($url);
        $pageItem = $this->workItemFactory->fromUrl($document);
        $rewritten = $this->pathCalculator->relativeTo($pageItem->outputPath(), $item->outputPath()) . $fragment;

        return UrlConversion::changed($rewritten, $item);
    }

    /**
     * @return array{0: string, 1: string} [raw without '#fragment', fragment incl. '#' or '']
     */
    private function splitFragment(string $raw): array
    {
        $hashAt = strpos($raw, '#');
        if ($hashAt === false) {
            return [$raw, ''];
        }

        return [substr($raw, 0, $hashAt), substr($raw, $hashAt)];
    }
}
