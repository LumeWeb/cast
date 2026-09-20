<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export\Rewrite;

use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\Rewrite\JsRewriter;
use LumeWeb\Cast\Export\Rewrite\RewriteContext;
use LumeWeb\Cast\Export\Url;
use PHPUnit\Framework\TestCase;

/**
 * JS/script rewriter. HTML and JS MUST use the same destination mode, so a
 * root-relative asset inside a JS string converts to the same offline shape as
 * an HTML src attribute. JSON-in-script payloads delegate to the JSON rewriter;
 * sourceMappingURL/sourceURL directives are converted; malformed or plain JS is
 * left alone apart from clean URL references.
 */
final class JsRewriterTest extends TestCase
{
    private JsRewriter $rewriter;

    protected function setUp(): void
    {
        $this->rewriter = new JsRewriter();
    }

    /**
     * @return array{0: CapturingQueueCollector, 1: string}
     */
    private function rewrite(string $js): array
    {
        $queue = new CapturingQueueCollector();
        $document = new Url('https', 'example.com', null, '/2013/01/11/page-a/', '');
        $context = new RewriteContext($document, Origin::fromUrl($document), $queue);

        return [$queue, $this->rewriter->rewrite($js, $context)];
    }

    public function testK5RootRelativeAssetInJsMatchesHtmlShape(): void
    {
        [$queue, $out] = $this->rewrite('var logo = "/wp-content/themes/x/logo.png";');

        self::assertSame('var logo = "./../../../../wp-content/themes/x/logo.png";', $out);
        self::assertSame(['wp-content/themes/x/logo.png'], $queue->outputPaths());
    }

    public function testJsonInScriptDelegatesToJsonRewriter(): void
    {
        [$queue, $out] = $this->rewrite('{"url":"https:\\/\\/example.com\\/about\\/"}');

        self::assertSame('{"url":".\\/..\\/..\\/..\\/..\\/about\\/index.html"}', $out);
        self::assertSame(['about/index.html'], $queue->outputPaths());
    }

    public function testSourceMappingUrlIsRewritten(): void
    {
        [$queue, $out] = $this->rewrite('//# sourceMappingURL=/wp-content/themes/x/style.css.map');

        self::assertStringContainsString('sourceMappingURL=./../../../../wp-content/themes/x/style.css.map', $out);
        self::assertContains('wp-content/themes/x/style.css.map', $queue->outputPaths());
    }

    public function testSourceUrlIsRewritten(): void
    {
        [$queue, $out] = $this->rewrite('//@ sourceURL=//example.com/app.js');

        self::assertStringContainsString('sourceURL=./../../../../app.js', $out);
        self::assertContains('app.js', $queue->outputPaths());
    }

    public function testProtocolRelativeInJsStringIsConverted(): void
    {
        [$queue, $out] = $this->rewrite('var s = "//example.com/logo.png";');

        self::assertSame('var s = "./../../../../logo.png";', $out);
        self::assertSame(['logo.png'], $queue->outputPaths());
    }

    public function testAbsoluteOriginAssetUrlInsideJsIsConverted(): void
    {
        [$queue, $out] = $this->rewrite('fetch("https://example.com/wp-content/themes/x/logo.png");');

        self::assertSame('fetch("./../../../../wp-content/themes/x/logo.png");', $out);
        self::assertSame(['wp-content/themes/x/logo.png'], $queue->outputPaths());
    }

    public function testExternalUrlInsideJsIsUnchangedAndUnqueued(): void
    {
        [$queue, $out] = $this->rewrite('var api = "https://api.other.com/v1";');

        self::assertSame('var api = "https://api.other.com/v1";', $out);
        self::assertCount(0, $queue);
    }

    public function testPlainJsWithoutUrlsIsUnchanged(): void
    {
        $js = 'var a = 1; function f() { return a + 1; }';

        self::assertSame($js, $this->rewrite($js)[1]);
    }

    public function testLineCommentUrlsAreNotTreatedAsProtocolRelative(): void
    {
        $js = "// a plain line comment\nalert('hi');";

        [$queue, $out] = $this->rewrite($js);

        self::assertStringContainsString('// a plain line comment', $out);
        self::assertCount(0, $queue);
    }
}
