<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export\Rewrite;

use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\Rewrite\CssRewriter;
use LumeWeb\Cast\Export\Rewrite\RewriteContext;
use LumeWeb\Cast\Export\Url;
use PHPUnit\Framework\TestCase;

final class CssRewriterTest extends TestCase
{
    private CssRewriter $rewriter;

    protected function setUp(): void
    {
        $this->rewriter = new CssRewriter();
    }

    /**
     * The CSS file URL is the resolution base, never the page URL. A relative
     * url(../fonts/a.woff2) inside /wp-content/themes/x/style.css must resolve
     * against the stylesheet and keep the path tree (flattening would break it).
     * The catalog prints `url(../fonts/a.woff2)` for short; the offline
     * calculator's canonical './' form is ./../fonts/a.woff2.
     */
    public function testK5CssUrlRelativeResolvesAgainstCssFileUrl(): void
    {
        [, $output] = $this->rewrite('@font-face { src: url(../fonts/a.woff2) format("woff2"); }');

        self::assertSame('@font-face { src: url(./../fonts/a.woff2) format("woff2"); }', $output);
    }

    public function testK5CssUrlRelativeQueuesTheResolvedAsset(): void
    {
        [$queue] = $this->rewrite('@font-face { src: url(../fonts/a.woff2); }');

        self::assertSame(['wp-content/themes/fonts/a.woff2'], $queue->outputPaths());
    }

    public function testK5CssDataSvgWithRgbIsUnchanged(): void
    {
        $css = "a { background-image: url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\"><path fill=\"rgb(0,0,0)\" d=\"M0 0h10v10z\"/></svg>'); }";

        self::assertSame($css, $this->rewrite($css)[1]);
    }

    public function testUnquotedDataSvgWithRgbIncludingSpacesIsUnchanged(): void
    {
        $css = 'a { background: url(data:image/svg+xml;utf8,<svg><circle fill="rgb(0 0 0 / .5)"/></svg>) center; }';

        self::assertSame($css, $this->rewrite($css)[1]);
    }

    public function testPlainDataUriIncludingBase64IsUnchanged(): void
    {
        $css = 'a { background: url("data:image/png;base64,iVBORw0KGgoAAAANSUhEUg==") no-repeat; }';

        self::assertSame($css, $this->rewrite($css)[1]);
    }

    public function testK5ElementorIconEntityBecomesCssEscape(): void
    {
        self::assertSame(
            'a::before { content: "\\f10e"; }',
            $this->rewrite('a::before { content: "&#61710;"; }')[1]
        );
    }

    public function testElementorIconSingleQuotedContent(): void
    {
        self::assertSame(
            "a::before { content: '\\e900'; }",
            $this->rewrite("a::before { content: '&#59648;'; }")[1]
        );
    }

    public function testQuoteStyleIsPreservedWhenRewriting(): void
    {
        self::assertSame(
            'a { src: url("./../fonts/a.woff2"); }',
            $this->rewrite('a { src: url("../fonts/a.woff2"); }')[1]
        );
        self::assertSame(
            "a { src: url('./../fonts/a.woff2'); }",
            $this->rewrite("a { src: url('../fonts/a.woff2'); }")[1]
        );
        self::assertSame(
            'a { src: url(./../fonts/a.woff2); }',
            $this->rewrite('a { src: url(../fonts/a.woff2); }')[1]
        );
    }

    public function testImportUrlFormIsRewritten(): void
    {
        self::assertSame(
            '@import url("./../../../other.css");',
            $this->rewrite('@import url("https://example.com/other.css");')[1]
        );
    }

    public function testImportBareQuotedFormIsRewritten(): void
    {
        self::assertSame(
            '@import "./../../../other.css";',
            $this->rewrite('@import "https://example.com/other.css";')[1]
        );
        self::assertSame(
            "@import url('./../../../other.css');",
            $this->rewrite("@import url('https://example.com/other.css');")[1]
        );
    }

    public function testAbsoluteOriginUrlBecomesOfflinePathFromCssFile(): void
    {
        self::assertSame(
            'a { background: url(./../../uploads/2024/01/photo.jpg); }',
            $this->rewrite('a { background: url(https://example.com/wp-content/uploads/2024/01/photo.jpg); }')[1]
        );
    }

    public function testProtocolRelativeOriginUrlBecomesOfflinePath(): void
    {
        self::assertSame(
            'a { src: url(./../../../style2.css); }',
            $this->rewrite('a { src: url(//example.com/style2.css); }')[1]
        );
    }

    public function testExternalUrlIsUnchangedAndUnqueued(): void
    {
        $css = 'a { background: url(https://res.cloudinary.com/demo/f_auto,q_auto/b.jpg); }';

        self::assertSame($css, $this->rewrite($css)[1]);
        self::assertSame(0, $this->rewrite($css)[0]->count());
    }

    public function testFragmentReferenceAndEmptyUrlUnchanged(): void
    {
        self::assertSame('a { mask: url(#icon); }', $this->rewrite('a { mask: url(#icon); }')[1]);
    }

    public function testUrlInsideCommentIsNotRewritten(): void
    {
        $css = '/* old: url(https://example.com/x.png) */ a { color: red; }';

        self::assertSame($css, $this->rewrite($css)[1]);
    }

    public function testUrlTextInsideStringIsNotRewritten(): void
    {
        $css = 'a::after { content: "see url(https://example.com/x.png)"; }';

        self::assertSame($css, $this->rewrite($css)[1]);
    }

    public function testDataSvgContainingUrlIsNotTerminatedEarly(): void
    {
        $css = 'a { background: url(data:image/svg+xml;utf8,<text href="https://example.com/x">rgb(1,2,3)</text>); }';

        self::assertSame($css, $this->rewrite($css)[1]);
    }

    public function testBareUrlWithBalancedInnerParensScansToMatchingCloseParen(): void
    {
        // The unquoted url() scanner tracks nested parens so an inner (group)
        // inside the value never ends the url() early; the value is scanned to
        // the matching close paren and trailing CSS survives it.
        [$queue, $out] = $this->rewrite(
            '@font-face { src: url(../fonts/icon(2).woff2) format("woff2"); }'
        );

        self::assertSame(
            '@font-face { src: url(./../fonts/icon(2).woff2) format("woff2"); }',
            $out
        );
        self::assertSame(['wp-content/themes/fonts/icon(2).woff2'], $queue->outputPaths());
    }

    public function testDevSourceUrlBlockCommentIsStrippedLeavingSurroundingCssUntouched(): void
    {
        $css = "/* keep this */\n"
            . ".block{min-height:28px}\n"
            . "/*# sourceURL=http://aiderdesk.lan:18080/wp-includes/blocks/spacer/style.min.css */\n"
            . ".spacer{height:100px}\n"
            . "/*# sourceURL=wp-emoji-styles-inline-css */\n"
            . ".emoji{vertical-align:middle}\n";

        $output = $this->rewrite($css)[1];

        // The dev pragmas are gone; the ordinary comment and every rule around
        // them are untouched, so stripping never clobbers real comments.
        self::assertSame(
            "/* keep this */\n.block{min-height:28px}\n\n.spacer{height:100px}\n\n.emoji{vertical-align:middle}\n",
            $output
        );
        self::assertStringNotContainsString('sourceURL', $output);
        self::assertStringNotContainsString('aiderdesk.lan', $output);
    }

    public function testDevSourceUrlLineCommentVariantsAreStripped(): void
    {
        $css = "a{color:red}\n"
            . "//# sourceURL=http://aiderdesk.lan:18080/app.css\n"
            . "b{color:blue}\n"
            . "//@ sourceURL=http://aiderdesk.lan:18080/app.css\n"
            . "c{color:green}\n";

        $output = $this->rewrite($css)[1];

        self::assertSame("a{color:red}\n\nb{color:blue}\n\nc{color:green}\n", $output);
        self::assertStringNotContainsString('sourceURL', $output);
        self::assertStringNotContainsString('aiderdesk.lan', $output);
    }

    public function testAbsoluteSourceMappingUrlIsStrippedRelativeIsKept(): void
    {
        // Unit pin: an absolute http(s) sourceMappingURL would leak the origin,
        // so it is stripped; a relative map reference (this export mirrors no
        // source maps) stays untouched.
        $absolute = '/*# sourceMappingURL=https://example.com/wp-content/themes/x/style.css.map */ .a{color:red}';
        $relative = '/*# sourceMappingURL=style.css.map */ .b{color:blue}';

        $output = $this->rewrite($absolute . "\n" . $relative)[1];

        self::assertSame(' .a{color:red}' . "\n" . '/*# sourceMappingURL=style.css.map */ .b{color:blue}', $output);
        self::assertStringContainsString('sourceMappingURL=style.css.map', $output);
        self::assertStringNotContainsString('https://example.com', $output);
    }

    public function testCommentMentioningSourceUrlIsNotTreatedAsPragma(): void
    {
        $css = '/* note: the sourceURL marker is missing here */ .a{color:red}';

        self::assertSame($css, $this->rewrite($css)[1]);
    }

    /**
     * @return array{0: CapturingQueueCollector, 1: string}
     */
    private function rewrite(string $css): array
    {
        $queue = new CapturingQueueCollector();
        $document = $this->cssFileUrl();
        $context = new RewriteContext($document, Origin::fromUrl($document), $queue);

        return [$queue, $this->rewriter->rewrite($css, $context)];
    }

    private function cssFileUrl(): Url
    {
        return new Url('https', 'example.com', null, '/wp-content/themes/x/style.css', '');
    }
}
