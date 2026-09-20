<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export\Rewrite;

use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\Rewrite\RewriteContext;
use LumeWeb\Cast\Export\Rewrite\XmlRewriter;
use LumeWeb\Cast\Export\Url;
use PHPUnit\Framework\TestCase;

/**
 * XML URL fields (sitemaps, feeds). Every absolute or
 * protocol-relative URL token is resolved through the queue check and converted
 * to an offline path from the XML file's own location. External URLs (including
 * schema namespaces) come back unchanged because the origin check rejects them.
 */
final class XmlRewriterTest extends TestCase
{
    private XmlRewriter $rewriter;

    protected function setUp(): void
    {
        $this->rewriter = new XmlRewriter();
    }

    /**
     * @return array{0: CapturingQueueCollector, 1: string}
     */
    private function rewrite(string $xml): array
    {
        $queue = new CapturingQueueCollector();
        $document = new Url('https', 'example.com', null, '/sitemap.xml', '');
        $context = new RewriteContext($document, Origin::fromUrl($document), $queue);

        return [$queue, $this->rewriter->rewrite($xml, $context)];
    }

    public function testSitemapLocUrlsAreConvertedToOfflinePaths(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . '<url><loc>https://example.com/about/</loc><lastmod>2024-01-01</lastmod></url>'
            . '<url><loc>https://example.com/2013/01/11/page-a/</loc></url>'
            . '</urlset>';

        [$queue, $out] = $this->rewrite($xml);

        self::assertStringContainsString('<loc>./about/index.html</loc>', $out);
        self::assertStringContainsString('<loc>./2013/01/11/page-a/index.html</loc>', $out);
        self::assertStringContainsString('<lastmod>2024-01-01</lastmod>', $out);
        self::assertSame(['about/index.html', '2013/01/11/page-a/index.html'], $queue->outputPaths());
    }

    public function testExternalNamespaceUrlsAreNotConverted(): void
    {
        $xml = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'
            . '<url><loc>https://example.com/about/</loc></url>'
            . '</urlset>';

        [$queue, $out] = $this->rewrite($xml);

        self::assertStringContainsString('http://www.sitemaps.org/schemas/sitemap/0.9', $out);
        self::assertStringContainsString('http://www.google.com/schemas/sitemap-image/1.1', $out);
        self::assertStringContainsString('<loc>./about/index.html</loc>', $out);
        self::assertSame(['about/index.html'], $queue->outputPaths());
    }

    public function testCdataWrappedUrlsAreConvertedLeavingMarkers(): void
    {
        $xml = '<urlset><url><loc><![CDATA[https://example.com/about/]]></loc></url></urlset>';

        [$queue, $out] = $this->rewrite($xml);

        self::assertStringContainsString('<loc><![CDATA[./about/index.html]]></loc>', $out);
        self::assertSame(['about/index.html'], $queue->outputPaths());
    }

    public function testProtocolRelativeUrlIsConverted(): void
    {
        $xml = '<urlset><url><loc>//example.com/feed/</loc></url></urlset>';

        [$queue, $out] = $this->rewrite($xml);

        self::assertStringContainsString('<loc>./feed/index.html</loc>', $out);
        self::assertSame(['feed/index.html'], $queue->outputPaths());
    }

    public function testFeedLinkAndGuidFieldsAreConverted(): void
    {
        $xml = '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">'
            . '<channel>'
            . '<title>Site</title>'
            . '<link>https://example.com/feed/</link>'
            . '<item><guid isPermaLink="false">https://example.com/?p=1</guid></item>'
            . '</channel>'
            . '</rss>';

        [$queue, $out] = $this->rewrite($xml);

        self::assertStringContainsString('<link>./feed/index.html</link>', $out);
        self::assertStringContainsString('http://www.w3.org/2005/Atom', $out);
        // ?p=1 is a page with a query, so it is stored under the hashed __qs dir.
        self::assertStringContainsString('<guid isPermaLink="false">./__qs/', $out);
        self::assertStringContainsString('/index.html</guid>', $out);
        self::assertContains('feed/index.html', $queue->outputPaths());
    }

    public function testEmptyAndPlainXmlIsUnchanged(): void
    {
        $xml = '<?xml version="1.0"?><root><empty/></root>';

        [, $out] = $this->rewrite($xml);

        self::assertSame($xml, $out);
    }
}
