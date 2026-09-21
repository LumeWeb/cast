<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export\Rewrite;

use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\Rewrite\HeadStripper;
use LumeWeb\Cast\Export\Rewrite\RewriteContext;
use LumeWeb\Cast\Export\Url;
use PHPUnit\Framework\TestCase;

/**
 * Copy-only head stripping: the rewritten copy must not advertise
 * WordPress, while the allowlist (canonical, Open Graph, JSON-LD, robots,
 * hreflang, stylesheets, and so on) must survive untouched.
 */
final class HeadStripperTest extends TestCase
{
    private HeadStripper $stripper;
    private RewriteContext $context;

    protected function setUp(): void
    {
        $this->stripper = new HeadStripper();
        $document = new Url('https', 'example.com', null, '/2013/01/11/page-a/', '');
        $this->context = new RewriteContext($document, Origin::fromUrl($document), new CapturingQueueCollector());
    }

    public function testStripsWordPressFingerprints(): void
    {
        $head = '<head>'
            . '<meta charset="UTF-8">'
            . '<link rel="EditURI" type="application/rsd+xml" title="RSD" href="https://example.com/xmlrpc.php?rsd" />'
            . '<link rel="wlwmanifest" type="application/wlwmanifest+xml" href="https://example.com/wp-includes/wlwmanifest.xml">'
            . '<link rel="shortlink" href="https://example.com/?p=42" />'
            . '<link rel="https://api.w.org/" href="https://example.com/wp-json/" />'
            . '<link rel="alternate" type="application/json+oembed" href="https://example.com/wp-json/oembed/1.0/embed" />'
            . '<link rel="alternate" type="text/xml+oembed" href="https://example.com/wp-json/oembed/1.0/embed" />'
            . '<meta name="generator" content="WordPress 6.7.1" />'
            . '<script type="text/javascript" src="https://example.com/wp-includes/js/wp-emoji-release.min.js?ver=6.7.1"></script>'
            . '<script src="https://example.com/wp-includes/js/wp-embed.min.js"></script>'
            . '</head>';

        $out = $this->stripper->strip($head, $this->context);

        self::assertStringNotContainsString('EditURI', $out);
        self::assertStringNotContainsString('wlwmanifest', $out);
        self::assertStringNotContainsString('shortlink', $out);
        self::assertStringNotContainsString('api.w.org', $out);
        self::assertStringNotContainsString('oembed', $out);
        self::assertStringNotContainsString('generator', $out);
        self::assertStringNotContainsString('wp-emoji', $out);
        self::assertStringNotContainsString('wp-embed.min.js', $out);
    }

    public function testStripsRssAlternates(): void
    {
        $head = '<head>'
            . '<link rel="alternate" type="application/rss+xml" title="Articles" href="https://example.com/feed/" />'
            . '<link rel="alternate" type="application/rss+xml" title="Comments" href="https://example.com/comments/feed/" />'
            . '</head>';

        $out = $this->stripper->strip($head, $this->context);

        self::assertStringNotContainsString('rss+xml', $out);
    }

    public function testStripsResourceHintsPointingAtOrigin(): void
    {
        $head = '<head>'
            . '<link rel="preconnect" href="https://example.com" />'
            . '<link rel="dns-prefetch" href="//example.com" />'
            . '<link rel="dns-prefetch" href="" />'
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />'
            . '</head>';

        $out = $this->stripper->strip($head, $this->context);

        self::assertStringNotContainsString('preconnect" href="https://example.com', $out);
        self::assertStringNotContainsString('dns-prefetch" href="//example.com', $out);
        self::assertStringNotContainsString('dns-prefetch" href=""', $out);
        self::assertStringContainsString('fonts.gstatic.com', $out);
    }

    public function testPreservesAllowlistedTags(): void
    {
        $head = '<head>'
            . '<link rel="canonical" href="https://example.com/about/" />'
            . '<meta property="og:type" content="website" />'
            . '<meta name="twitter:card" content="summary" />'
            . '<meta name="description" content="A description." />'
            . '<meta name="robots" content="index, follow" />'
            . '<link rel="alternate" hreflang="en" href="https://example.com/en/" />'
            . '<link rel="prev" href="https://example.com/2013/" />'
            . '<link rel="next" href="https://example.com/2013/01/" />'
            . '<link rel="stylesheet" href="https://example.com/wp-content/themes/x/style.css" />'
            . '<link rel="icon" href="https://example.com/favicon.ico" />'
            . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebSite"}</script>'
            . '<meta itemprop="url" content="https://example.com/about/" />'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '</head>';

        $out = $this->stripper->strip($head, $this->context);

        self::assertStringContainsString('rel="canonical"', $out);
        self::assertStringContainsString('og:type', $out);
        self::assertStringContainsString('twitter:card', $out);
        self::assertStringContainsString('name="description"', $out);
        self::assertStringContainsString('name="robots"', $out);
        self::assertStringContainsString('hreflang="en"', $out);
        self::assertStringContainsString('rel="prev"', $out);
        self::assertStringContainsString('rel="next"', $out);
        self::assertStringContainsString('rel="stylesheet"', $out);
        self::assertStringContainsString('rel="icon"', $out);
        self::assertStringContainsString('application/ld+json', $out);
        self::assertStringContainsString('schema.org', $out);
        self::assertStringContainsString('itemprop', $out);
        self::assertStringContainsString('viewport', $out);
    }

    public function testHeadOpenCloseTagsArePreserved(): void
    {
        $html = "<html><head><meta charset=\"UTF-8\"><title>Hi</title><meta name=\"generator\" content=\"WP\"/></head><body></body></html>";

        $out = $this->stripper->strip($html, $this->context);

        self::assertStringStartsWith('<html><head>', $out);
        self::assertStringContainsString('</head>', $out);
        self::assertStringNotContainsString('generator', $out);
    }

    public function testCaseInsensitiveMatching(): void
    {
        $head = '<HEAD>'
            . '<META NAME="GENERATOR" CONTENT="WordPress 6.7.1" />'
            . '<LINK REL="EditURI" HREF="https://example.com/xmlrpc.php?rsd" />'
            . '</HEAD>';

        $out = $this->stripper->strip($head, $this->context);

        self::assertStringNotContainsString('GENERATOR', $out);
        self::assertStringNotContainsString('EditURI', $out);
        self::assertStringContainsString('<HEAD>', $out);
    }

    public function testDocumentWithoutHeadIsUnchanged(): void
    {
        $html = '<body><p>no head here</p></body>';

        self::assertSame($html, $this->stripper->strip($html, $this->context));
    }
}
