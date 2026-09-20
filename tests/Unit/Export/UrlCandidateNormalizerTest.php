<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\InvalidUrl;
use LumeWeb\Cast\Export\OffOriginUrl;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\Url;
use LumeWeb\Cast\Export\UrlCandidateNormalizer;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\UrlRejection;
use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemFactory;
use PHPUnit\Framework\TestCase;

final class UrlCandidateNormalizerTest extends TestCase
{
    private UrlCandidateNormalizer $normalizer;
    private UrlCanonicalizer $canonicalizer;
    private Origin $origin;

    protected function setUp(): void
    {
        $this->canonicalizer = new UrlCanonicalizer();
        $this->normalizer = new UrlCandidateNormalizer();
        $this->origin = Origin::fromUrl($this->canonicalizer->canonicalize('https://example.com/'));
    }

    public function testNormalizeUrlCanonicalizesSameOriginCandidate(): void
    {
        $url = $this->normalizer->normalizeUrl('HTTPS://EXAMPLE.com/About/?B=2&A=1#team', $this->origin);

        self::assertSame('https', $url->scheme());
        self::assertSame('example.com', $url->host());
        self::assertSame('/About/', $url->path());
        self::assertSame('A=1&B=2', $url->query());
    }

    public function testNonOriginHostIsRejectedBeforeInsertion(): void
    {
        $this->expectException(OffOriginUrl::class);
        $this->normalizer->normalizeUrl('https://evil.example/x', $this->origin);
    }

    public function testDifferentSchemeAndPortAreRejected(): void
    {
        $this->expectException(OffOriginUrl::class);
        $this->normalizer->normalizeUrl('http://example.com/', $this->origin);
    }

    public function testMalformedCandidateThrowsInvalidUrl(): void
    {
        $this->expectException(InvalidUrl::class);
        $this->normalizer->normalizeUrl('not a url', $this->origin);
    }

    public function testWildcardPathsAndQueriesAreRejected(): void
    {
        // Glob/pattern pseudo-URLs (`/wp-*.php`, `/wp-admin/*`, `?s=*`) never
        // name one real resource and must never reach the queue.
        $shapes = [
            'https://example.com/wp-*.php',
            'https://example.com/wp-admin/*',
            'https://example.com/wp-content/uploads/*.png',
            'https://example.com/?s=*',
            'https://example.com/category/*/page/2/',
            'https://example.com/*.css',
        ];

        foreach ($shapes as $url) {
            try {
                $this->normalizer->normalizeUrl($url, $this->origin);
                self::fail("expected wildcard rejection for {$url}");
            } catch (InvalidUrl $exception) {
                self::assertSame(UrlRejection::Wildcard, $exception->rejection, $url);
            }
        }
    }

    public function testPercentEncodedAsteriskStaysAllowed(): void
    {
        // The percent-encoded `%2A` is one real resource's escape, not a glob:
        // it must keep passing through the single check untouched.
        $url = $this->normalizer->normalizeUrl('https://example.com/wp-content/%2A-about/', $this->origin);

        self::assertSame('/wp-content/%2A-about/', $url->path());
    }

    public function testWorkItemDerivedFromSameOriginUrl(): void
    {
        $url = $this->normalizer->normalizeUrl('https://example.com/about/', $this->origin);

        $item = $this->normalizer->workItem($url);

        self::assertInstanceOf(WorkItem::class, $item);
        self::assertSame('https://example.com/about/', $item->identity());
        self::assertSame(32, strlen($item->urlHash()));
    }

    public function testNormalizerProducesSameIdentityAsFactory(): void
    {
        $url = $this->normalizer->normalizeUrl('https://example.com/?s=hello', $this->origin);
        $expected = (new WorkItemFactory())->fromString('https://example.com/?s=hello');

        $item = $this->normalizer->workItem($url);

        self::assertSame($expected->identity(), $item->identity());
        self::assertSame($expected->urlHash(), $item->urlHash());
    }

    public function testUrlIsTypedUrl(): void
    {
        $url = $this->normalizer->normalizeUrl('https://example.com/', $this->origin);

        self::assertInstanceOf(Url::class, $url);
    }
}
