<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export\Rewrite;

use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\Rewrite\RewriteContext;
use LumeWeb\Cast\Export\Rewrite\TextRewriter;
use LumeWeb\Cast\Export\Url;
use PHPUnit\Framework\TestCase;

/**
 * robots.txt & friends. Sitemap: lines keep their label, the URL
 * is converted to an offline path from the text file's own location, and
 * disallow/allow rule paths (root-relative, not references) are never touched.
 */
final class TextRewriterTest extends TestCase
{
    private TextRewriter $rewriter;

    protected function setUp(): void
    {
        $this->rewriter = new TextRewriter();
    }

    /**
     * @return array{0: CapturingQueueCollector, 1: string}
     */
    private function rewrite(string $text): array
    {
        $queue = new CapturingQueueCollector();
        $document = new Url('https', 'example.com', null, '/robots.txt', '');
        $context = new RewriteContext($document, Origin::fromUrl($document), $queue);

        return [$queue, $this->rewriter->rewrite($text, $context)];
    }

    public function testK5SitemapLineUrlConvertedLabelPreserved(): void
    {
        $robots = "User-agent: *\n"
            . "Disallow: /wp-admin/\n"
            . "Allow: /wp-admin/admin-ajax.php\n"
            . 'Sitemap: https://example.com/sitemap.xml' . "\n";

        [$queue, $out] = $this->rewrite($robots);

        self::assertStringContainsString('Sitemap: ./sitemap.xml', $out);
        self::assertStringContainsString('User-agent: *', $out);
        self::assertStringContainsString('Disallow: /wp-admin/', $out);
        self::assertStringContainsString('Allow: /wp-admin/admin-ajax.php', $out);
        self::assertSame(['sitemap.xml'], $queue->outputPaths());
    }

    public function testProtocolRelativeSitemapUrlIsConverted(): void
    {
        [$queue, $out] = $this->rewrite('Sitemap: //example.com/sitemap_index.xml' . "\n");

        self::assertStringContainsString('Sitemap: ./sitemap_index.xml', $out);
        self::assertSame(['sitemap_index.xml'], $queue->outputPaths());
    }

    public function testExternalSitemapUrlIsLeftUnchangedAndUnqueued(): void
    {
        [$queue, $out] = $this->rewrite('Sitemap: https://www.google.com/sitemap.xml' . "\n");

        self::assertStringContainsString('Sitemap: https://www.google.com/sitemap.xml', $out);
        self::assertSame([], $queue->outputPaths());
    }

    public function testRootRelativeRulePathsAreNotTouched(): void
    {
        $robots = "Disallow: /wp-admin/\nDisallow: /wp-includes/\nUser-agent: *\n";

        [, $out] = $this->rewrite($robots);

        self::assertSame($robots, $out);
    }

    public function testPlainTextIsUnchanged(): void
    {
        $text = "nothing here\njust words\n";

        [, $out] = $this->rewrite($text);

        self::assertSame($text, $out);
    }
}
