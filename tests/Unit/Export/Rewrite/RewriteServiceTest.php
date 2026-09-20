<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export\Rewrite;

use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\Rewrite\ArrayWarningCollector;
use LumeWeb\Cast\Export\Rewrite\DestinationMode;
use LumeWeb\Cast\Export\Rewrite\RewriteContext;
use LumeWeb\Cast\Export\Rewrite\RewriteService;
use LumeWeb\Cast\Export\Rewrite\WarningCollector;
use LumeWeb\Cast\Export\Url;
use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemFactory;
use PHPUnit\Framework\TestCase;

/**
 * Rewrite service facade: dispatches a captured WorkItem's body to the
 * right rewriter by kind + extension, enforces that this rewriter only supports
 * offline-zip, runs the leftover-origin reporter as a last pass on text-like
 * content (never smash the host), and passes binary fixed assets through
 * untouched. Pure PHP, no ZIP/publish/UI.
 */
final class RewriteServiceTest extends TestCase
{
    private RewriteService $service;
    private WorkItemFactory $factory;

    protected function setUp(): void
    {
        $this->service = new RewriteService();
        $this->factory = new WorkItemFactory();
    }

    /**
     * @return array{0: CapturingQueueCollector, 1: RewriteContext}
     */
    private function context(string $path): array
    {
        $queue = new CapturingQueueCollector();
        $document = new Url('https', 'example.com', null, $path, '');

        return [$queue, new RewriteContext($document, Origin::fromUrl($document), $queue)];
    }

    public function testHtmlPageDispatchesToHtmlRewriterIncludingK9Strip(): void
    {
        $item = $this->factory->fromString('https://example.com/2013/01/11/page-a/');
        [$queue, $context] = $this->context('/2013/01/11/page-a/');
        $warnings = new ArrayWarningCollector();

        $out = $this->service->rewrite(
            $item,
            '<html><head><meta name="generator" content="WordPress 6.8">'
            . '<link rel="canonical" href="https://example.com/about/"></head>'
            . '<body><img src="/wp-content/themes/x/logo.png"></body></html>',
            $context,
            $warnings,
        );

        self::assertStringContainsString('<img src="./../../../../wp-content/themes/x/logo.png">', $out);
        self::assertStringNotContainsString('WordPress 6.8', $out);
        self::assertStringContainsString('rel="canonical"', $out);
        self::assertContains('wp-content/themes/x/logo.png', $queue->outputPaths());
        self::assertFalse($warnings->hasLeftoverOrigin());
    }

    public function testPageStyleDevSourceUrlCommentIsStrippedSurroundingCssUntouched(): void
    {
        // A page-like text asset (HTML page) whose inlined <style> contains the
        // origin's dev sourceURL pragmas: the exported body must lose them
        // while the surrounding CSS — including an ordinary comment — survives
        // byte-for-byte.
        $item = $this->factory->fromString('https://example.com/2013/01/11/page-a/');
        [$queue, $context] = $this->context('/2013/01/11/page-a/');
        $warnings = new ArrayWarningCollector();

        $out = $this->service->rewrite(
            $item,
            '<html><head><style>'
            . '/* keep this */'
            . '.hero{color:red}'
            . '/*# sourceURL=http://aiderdesk.lan:18080/wp-includes/blocks/spacer/style.min.css */'
            . '/*# sourceURL=wp-emoji-styles-inline-css */'
            . '.spacer{min-height:28px}'
            . '</style></head><body>hi</body></html>',
            $context,
            $warnings,
        );

        self::assertStringNotContainsString('sourceURL', $out);
        self::assertStringNotContainsString('aiderdesk.lan', $out);
        self::assertStringContainsString('/* keep this */', $out);
        self::assertStringContainsString('.hero{color:red}', $out);
        self::assertStringContainsString('.spacer{min-height:28px}', $out);
        self::assertFalse($warnings->hasLeftoverOrigin());
    }

    public function testCssAssetIsRewrittenAgainstCssFileUrl(): void
    {
        $item = $this->factory->fromString('https://example.com/wp-content/themes/x/style.css');
        [$queue, $context] = $this->context('/wp-content/themes/x/style.css');
        $warnings = new ArrayWarningCollector();

        $out = $this->service->rewrite(
            $item,
            '@font-face { src: url(../fonts/a.woff2); }',
            $context,
            $warnings,
        );

        self::assertSame('@font-face { src: url(./../fonts/a.woff2); }', $out);
        self::assertSame(['wp-content/themes/fonts/a.woff2'], $queue->outputPaths());
        self::assertFalse($warnings->hasLeftoverOrigin());
    }

    public function testJsAssetIsRewrittenWithOfflineDepthFromJsFile(): void
    {
        $item = $this->factory->fromString('https://example.com/wp-content/themes/x/app.js');
        [$queue, $context] = $this->context('/wp-content/themes/x/app.js');
        $warnings = new ArrayWarningCollector();

        $out = $this->service->rewrite($item, 'var logo = "/static/logo.png";', $context, $warnings);

        self::assertSame('var logo = "./../../../static/logo.png";', $out);
        self::assertSame(['static/logo.png'], $queue->outputPaths());
        self::assertFalse($warnings->hasLeftoverOrigin());
    }

    public function testJsonAssetIsRewrittenAndReEncoded(): void
    {
        $item = $this->factory->fromString('https://example.com/wp-content/themes/x/config.json');
        [$queue, $context] = $this->context('/wp-content/themes/x/config.json');
        $warnings = new ArrayWarningCollector();

        $out = $this->service->rewrite(
            $item,
            '{"url":"https:\\/\\/example.com\\/about\\/"}',
            $context,
            $warnings,
        );

        self::assertSame('{"url":".\\/..\\/..\\/..\\/about\\/index.html"}', $out);
        self::assertSame(['about/index.html'], $queue->outputPaths());
        self::assertFalse($warnings->hasLeftoverOrigin());
    }

    public function testXmlAssetLocUrlsAreConverted(): void
    {
        $item = $this->factory->fromString('https://example.com/sitemap.xml');
        [$queue, $context] = $this->context('/sitemap.xml');
        $warnings = new ArrayWarningCollector();

        $out = $this->service->rewrite(
            $item,
            '<?xml version="1.0"?><urlset><url><loc>https://example.com/about/</loc></url></urlset>',
            $context,
            $warnings,
        );

        self::assertStringContainsString('<loc>./about/index.html</loc>', $out);
        self::assertSame(['about/index.html'], $queue->outputPaths());
        self::assertFalse($warnings->hasLeftoverOrigin());
    }

    public function testTextKindSitemapUrlIsConvertedLabelPreserved(): void
    {
        $item = $this->factory->fromString('https://example.com/robots.txt');
        [$queue, $context] = $this->context('/robots.txt');
        $warnings = new ArrayWarningCollector();

        $out = $this->service->rewrite(
            $item,
            "User-agent: *\nSitemap: https://example.com/sitemap.xml\n",
            $context,
            $warnings,
        );

        self::assertStringContainsString('Sitemap: ./sitemap.xml', $out);
        self::assertStringContainsString('User-agent: *', $out);
        self::assertSame(['sitemap.xml'], $queue->outputPaths());
        self::assertFalse($warnings->hasLeftoverOrigin());
    }

    public function testBinaryFixedAssetIsPassedThroughUntouched(): void
    {
        $item = $this->factory->fromString('https://example.com/wp-content/uploads/2024/photo.png');
        [$queue, $context] = $this->context('/wp-content/uploads/2024/photo.png');
        $warnings = new ArrayWarningCollector();
        $bytes = "\x89PNG\r\n\x1a\nfake-binary-https://example.com/leftover-bytes";

        $out = $this->service->rewrite($item, $bytes, $context, $warnings);

        self::assertSame($bytes, $out);
        self::assertCount(0, $queue);
        // Binary assets skip the leftover-origin pass (no byte-scan false alarms).
        self::assertFalse($warnings->hasLeftoverOrigin());
    }

    public function testLeftoverOriginInJsCommentIsRecordedAsWarningNotSmashed(): void
    {
        $item = $this->factory->fromString('https://example.com/wp-content/themes/x/app.js');
        [$queue, $context] = $this->context('/wp-content/themes/x/app.js');
        $warnings = new ArrayWarningCollector();
        $js = "var a = \"hello\";\n// left over: https://example.com/remaining\n";

        $out = $this->service->rewrite($item, $js, $context, $warnings);

        // Comment is preserved byte-for-byte; the origin URL is NOT host-smashed.
        self::assertSame($js, $out);
        self::assertTrue($warnings->hasLeftoverOrigin());
        self::assertSame(WarningCollector::ORIGIN_LEFTOVER, $warnings->all()[0]['category']);
        self::assertCount(0, $queue);
    }

    public function testUnsupportedDestinationModeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RewriteService('absolute-host');
    }

    public function testOfflineZipAliasIsAccepted(): void
    {
        $service = new RewriteService('zip');

        self::assertSame(DestinationMode::OfflineZip, $service->mode());
    }
}
