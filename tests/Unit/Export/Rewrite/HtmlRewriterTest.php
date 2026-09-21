<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export\Rewrite;

use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\Rewrite\HtmlRewriter;
use LumeWeb\Cast\Export\Rewrite\RewriteContext;
use LumeWeb\Cast\Export\Url;
use PHPUnit\Framework\TestCase;

/**
 * HTML pipeline (offline-zip mode): tag/attribute map for
 * href/src/srcset/imagesrcset/poster/action/formaction/data-src*, inline
 * style= and <style> through the CSS rewriter, Elementor/JSON data-* through
 * the JSON rewriter, script src + inline content through the JS rewriter, all
 * resolved against the page document URL and queued only for in-origin URLs.
 * Comments, script/style raw content, and entity-encoded markup must survive
 * byte-for-byte. HTML and JS must produce the SAME './' offline shape
 * (html-js-same-mode). Head-strip runs as the last HTML pipeline step.
 */
final class HtmlRewriterTest extends TestCase
{
    private HtmlRewriter $rewriter;

    protected function setUp(): void
    {
        $this->rewriter = new HtmlRewriter();
    }

    /**
     * @return array{0: CapturingQueueCollector, 1: string}
     */
    private function rewrite(string $html): array
    {
        $queue = new CapturingQueueCollector();
        $document = new Url('https', 'example.com', null, '/2013/01/11/page-a/', '');
        $context = new RewriteContext($document, Origin::fromUrl($document), $queue);

        return [$queue, $this->rewriter->rewrite($html, $context)];
    }

    public function testK5HtmlSameModeAsJsRootRelativeSrc(): void
    {
        [$queue, $out] = $this->rewrite('<img src="/wp-content/themes/x/logo.png">');

        // identical offline shape to the JS test: html-js-same-mode.
        self::assertSame('<img src="./../../../../wp-content/themes/x/logo.png">', $out);
        self::assertSame(['wp-content/themes/x/logo.png'], $queue->outputPaths());
    }

    public function testHrefConvertedAndFragmentPreserved(): void
    {
        [$queue, $out] = $this->rewrite('<a href="/about/#team">Team</a>');

        self::assertSame('<a href="./../../../../about/index.html#team">Team</a>', $out);
        self::assertSame(['about/index.html'], $queue->outputPaths());
    }

    public function testHashOnlyHrefUnchangedAndUnqueued(): void
    {
        [$queue, $out] = $this->rewrite('<a href="#section-2">2</a><a href="#top">top</a>');

        self::assertStringContainsString('href="#section-2"', $out);
        self::assertStringContainsString('href="#top"', $out);
        self::assertCount(0, $queue);
    }

    public function testProtocolRelativeHrefConverted(): void
    {
        [$queue, $out] = $this->rewrite('<a href="//example.com/wp-content/themes/x/style.css">css</a>');

        self::assertSame('<a href="./../../../../wp-content/themes/x/style.css">css</a>', $out);
        self::assertSame(['wp-content/themes/x/style.css'], $queue->outputPaths());
    }

    public function testNonWebSchemesPreserved(): void
    {
        [$queue, $out] = $this->rewrite(
            '<a href="mailto:hi@example.com">m</a><a href="tel:+1555">t</a>'
            . '<a href="javascript:void(0)">j</a><a href="data:text/plain,hi">d</a>'
        );

        self::assertStringContainsString('href="mailto:hi@example.com"', $out);
        self::assertStringContainsString('href="tel:+1555"', $out);
        self::assertStringContainsString('href="javascript:void(0)"', $out);
        self::assertStringContainsString('href="data:text/plain,hi"', $out);
        self::assertCount(0, $queue);
    }

    public function testSrcsetCloudinaryCommaPreserved(): void
    {
        [$queue, $out] = $this->rewrite(
            '<img srcset="https://example.com/a.jpg 800w, https://res.cloudinary.com/demo/f_auto,q_auto/b.jpg 1600w">'
        );

        self::assertSame(
            '<img srcset="./../../../../a.jpg 800w, https://res.cloudinary.com/demo/f_auto,q_auto/b.jpg 1600w">',
            $out
        );
        self::assertSame(['a.jpg'], $queue->outputPaths());
    }

    public function testImagesrcsetOnLinkIsSplitAndRewritten(): void
    {
        [$queue, $out] = $this->rewrite(
            '<link rel="preload" as="image" imagesrcset="//example.com/wp-content/themes/x/h.jpg 1x, https://example.com/wp-content/themes/x/h-2x.jpg 2x">'
        );

        self::assertSame(
            '<link rel="preload" as="image" imagesrcset="./../../../../wp-content/themes/x/h.jpg 1x, ./../../../../wp-content/themes/x/h-2x.jpg 2x">',
            $out
        );
        self::assertSame(['wp-content/themes/x/h.jpg', 'wp-content/themes/x/h-2x.jpg'], $queue->outputPaths());
    }

    public function testPosterActionAndFormactionAreRewritten(): void
    {
        [$queue, $out] = $this->rewrite(
            '<video poster="/wp-content/themes/x/p.jpg"></video>'
            . '<form action="/subscribe/"><button formaction="/other/">go</button></form>'
        );

        self::assertStringContainsString('poster="./../../../../wp-content/themes/x/p.jpg"', $out);
        self::assertStringContainsString('action="./../../../../subscribe/index.html"', $out);
        self::assertStringContainsString('formaction="./../../../../other/index.html"', $out);
        self::assertSame(
            ['wp-content/themes/x/p.jpg', 'subscribe/index.html', 'other/index.html'],
            $queue->outputPaths()
        );
    }

    public function testDataSrcAndDataBgAttributesAreRewritten(): void
    {
        [$queue, $out] = $this->rewrite(
            '<img data-src="/wp-content/themes/x/lazy.png" data-bg="/wp-content/themes/x/bg.jpg">'
        );

        self::assertStringContainsString('data-src="./../../../../wp-content/themes/x/lazy.png"', $out);
        self::assertStringContainsString('data-bg="./../../../../wp-content/themes/x/bg.jpg"', $out);
        self::assertSame(['wp-content/themes/x/lazy.png', 'wp-content/themes/x/bg.jpg'], $queue->outputPaths());
    }

    public function testElementorDataSettingsJsonIsRewrittenAndStillValid(): void
    {
        [$queue, $out] = $this->rewrite(
            "<div data-settings='{\"url\":\"https:\\/\\/example.com\\/a.jpg\"}'></div>"
        );

        self::assertSame("<div data-settings='{\"url\":\".\\/..\\/..\\/..\\/..\\/a.jpg\"}'></div>", $out);
        self::assertSame(['a.jpg'], $queue->outputPaths());
    }

    public function testInlineStyleAttributeIsRewritten(): void
    {
        [$queue, $out] = $this->rewrite(
            '<div style="background-image:url(\'/wp-content/themes/x/bg.png\')">x</div>'
        );

        self::assertSame(
            '<div style="background-image:url(\'./../../../../wp-content/themes/x/bg.png\')">x</div>',
            $out
        );
        self::assertSame(['wp-content/themes/x/bg.png'], $queue->outputPaths());
    }

    public function testStyleElementContentIsRewrittenAgainstPage(): void
    {
        [$queue, $out] = $this->rewrite('<style>.x{background:url(../img/bg.png)}</style>');

        self::assertSame('<style>.x{background:url(./../img/bg.png)}</style>', $out);
        self::assertSame(['2013/01/11/img/bg.png'], $queue->outputPaths());
    }

    public function testStyleElementDevSourceUrlCommentIsStripped(): void
    {
        [$queue, $out] = $this->rewrite(
            '<style>.x{background:url(../img/bg.png)}'
            . '/*# sourceURL=http://aiderdesk.lan:18080/wp-includes/blocks/spacer/style.min.css */'
            . '.spacer{min-height:28px}</style>'
        );

        self::assertStringNotContainsString('sourceURL', $out);
        self::assertStringNotContainsString('aiderdesk.lan', $out);
        self::assertStringContainsString('.x{background:url(./../img/bg.png)}', $out);
        self::assertStringContainsString('.spacer{min-height:28px}', $out);
        self::assertSame(['2013/01/11/img/bg.png'], $queue->outputPaths());
    }

    public function testInlineScriptContentMatchesHtmlShape(): void
    {
        [$queue, $out] = $this->rewrite('<script>var logo = "/wp-content/themes/x/logo.png";</script>');

        self::assertSame(
            '<script>var logo = "./../../../../wp-content/themes/x/logo.png";</script>',
            $out
        );
        self::assertSame(['wp-content/themes/x/logo.png'], $queue->outputPaths());
    }

    public function testScriptSrcIsRewritten(): void
    {
        [$queue, $out] = $this->rewrite('<script src="/wp-content/themes/x/app.js"></script>');

        self::assertSame('<script src="./../../../../wp-content/themes/x/app.js"></script>', $out);
        self::assertSame(['wp-content/themes/x/app.js'], $queue->outputPaths());
    }

    public function testCommentsArePreservedByteForByte(): void
    {
        $html = '<!-- <img src="/wp-content/themes/x/logo.png"> not rewritten --><p>hi</p>';

        [$queue, $out] = $this->rewrite($html);

        self::assertSame($html, $out);
        self::assertCount(0, $queue);
    }

    public function testEntityEncodedAttributeValueIsNotBroken(): void
    {
        [$queue, $out] = $this->rewrite('<a href="/about/?a=1&amp;b=2">x</a>');

        self::assertSame('<a href="/about/?a=1&amp;b=2">x</a>', $out);
        self::assertCount(0, $queue);
    }

    public function testExternalUrlIsUnchangedAndUnqueued(): void
    {
        [$queue, $out] = $this->rewrite('<img src="https://cdn.other.com/x.png">');

        self::assertSame('<img src="https://cdn.other.com/x.png">', $out);
        self::assertCount(0, $queue);
    }

    public function testK9HeadStripRunsLastRemovingGeneratorKeepingCanonical(): void
    {
        $html = '<html><head>'
            . '<meta name="generator" content="WordPress 6.8">'
            . '<link rel="canonical" href="https://example.com/2013/01/11/page-a/">'
            . '<meta property="og:image" content="https://example.com/wp-content/themes/x/og.png">'
            . '</head><body>hi</body></html>';

        [$queue, $out] = $this->rewrite($html);

        self::assertStringNotContainsString('generator', $out);
        self::assertStringNotContainsString('WordPress 6.8', $out);
        self::assertStringContainsString('rel="canonical"', $out);
        self::assertStringContainsString('og:image', $out);
        self::assertContains('wp-content/themes/x/og.png', $queue->outputPaths());
    }
}
