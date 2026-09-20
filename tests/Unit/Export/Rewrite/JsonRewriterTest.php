<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export\Rewrite;

use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\Rewrite\JsonRewriter;
use LumeWeb\Cast\Export\Rewrite\RewriteContext;
use LumeWeb\Cast\Export\Url;
use PHPUnit\Framework\TestCase;

final class JsonRewriterTest extends TestCase
{
    private JsonRewriter $rewriter;

    protected function setUp(): void
    {
        $this->rewriter = new JsonRewriter();
    }

    /**
     * Slash-bearing scalars under non-URL keys are data, not URLs: a date like
     * "2024/01/15" must be preserved verbatim (no rewrite, no capture queue).
     */
    public function testDateLikeValueUnderNonUrlKeyIsPreserved(): void
    {
        [$queue, $output] = $this->rewrite('{"date":"2024\/01\/15"}');

        self::assertSame('{"date":"2024\/01\/15"}', $output);
        self::assertCount(0, $queue);
    }

    /**
     * "16/9" reads like an aspect ratio, not a URL, and must survive untouched.
     */
    public function testRatioValueUnderNonUrlKeyIsPreserved(): void
    {
        [$queue, $output] = $this->rewrite('{"ratio":"16\/9"}');

        self::assertSame('{"ratio":"16\/9"}', $output);
        self::assertCount(0, $queue);
    }

    /**
     * Absolute http(s) URLs stay URL candidates under ANY key (tier 1), so a
     * caption that happens to hold a full URL is still rewritten and queued.
     */
    public function testAbsoluteUrlUnderAnyKeyStillRewritten(): void
    {
        [$queue, $output] = $this->rewrite('{"caption":"https:\/\/example.com\/a.jpg"}');

        self::assertSame('{"caption":".\/..\/..\/..\/..\/a.jpg"}', $output);
        self::assertSame(['a.jpg'], $queue->outputPaths());
    }

    /**
     * Explicit relative references under URL-context keys (backgroundUrl) still
     * resolve against the document and are rewritten offline (+ queued). The
     * expectations below are pinned identically by the later-layer test suite.
     */
    public function testRelativeUrlUnderUrlKeyResolvesAgainstDocument(): void
    {
        [$queue, $output] = $this->rewrite('{"backgroundUrl":"..\/img\/bg.png"}');

        self::assertSame('{"backgroundUrl":".\/..\/img\/bg.png"}', $output);
        self::assertSame(['2013/01/11/img/bg.png'], $queue->outputPaths());
    }

    /**
     * Root-relative references ("/wp-content/x") are always URLs and must be
     * rewritten to an offline path regardless of the surrounding key.
     */
    public function testRootRelativeUrlRewritten(): void
    {
        [$queue, $output] = $this->rewrite('{"src":"\/wp-content\/x\/logo.png"}');

        self::assertSame('{"src":".\/..\/..\/..\/..\/wp-content\/x\/logo.png"}', $output);
        self::assertSame(['wp-content/x/logo.png'], $queue->outputPaths());
    }

    /**
     * The enclosing-key context must thread through nested walks so deep
     * slash-bearing scalars under non-URL keys are preserved too.
     */
    public function testKeyContextThreadsThroughNestedWalk(): void
    {
        [$queue, $output] = $this->rewrite('{"nested":{"date":"2024\/01\/15"}}');

        self::assertSame('{"nested":{"date":"2024\/01\/15"}}', $output);
        self::assertCount(0, $queue);
    }

    /**
     * "curl" merely CONTAINS the url word as a substring; whole-token matching
     * must not treat it as a URL-context key, so the date-like value survives
     * verbatim (no rewrite, no capture queue).
     */
    public function testCurlValueUnderNonUrlKeyIsPreserved(): void
    {
        [$queue, $output] = $this->rewrite('{"curl":"2024\/01\/15"}');

        self::assertSame('{"curl":"2024\/01\/15"}', $output);
        self::assertCount(0, $queue);
    }

    /**
     * "lexicon" merely CONTAINS the icon word as a substring; whole-token
     * matching must not treat it as a URL-context key, so the docs path is
     * preserved verbatim (no rewrite, no capture queue).
     */
    public function testLexiconValueUnderNonUrlKeyIsPreserved(): void
    {
        [$queue, $output] = $this->rewrite('{"lexicon":"docs\/x"}');

        self::assertSame('{"lexicon":"docs\/x"}', $output);
        self::assertCount(0, $queue);
    }

    /**
     * "aspect" is not a URL-context key: the "16/9" ratio is preserved even
     * though it is slash-bearing.
     */
    public function testAspectRatioValueUnderNonUrlKeyIsPreserved(): void
    {
        [$queue, $output] = $this->rewrite('{"aspect":"16\/9"}');

        self::assertSame('{"aspect":"16\/9"}', $output);
        self::assertCount(0, $queue);
    }

    /**
     * A camelCase URL-context key matches whole tokens: featuredImageUrl splits
     * into featured/image/url, so a bare relative path underneath is rewritten
     * and queued like the plain image/url scalar keys.
     */
    public function testCamelCaseUrlKeyMatchesWholeToken(): void
    {
        [$queue, $output] = $this->rewrite('{"featuredImageUrl":"wp-content\/a.png"}');

        self::assertSame('{"featuredImageUrl":".\/wp-content\/a.png"}', $output);
        self::assertSame(['2013/01/11/page-a/wp-content/a.png'], $queue->outputPaths());
    }

    /**
     * Array elements inherit the parent key's URL context: each bare relative
     * path inside an images array is rewritten and queued, mirroring the
     * scalar {"images":"wp-content/a.png"} expectation.
     */
    public function testImageArrayElementsInheritUrlKeyContext(): void
    {
        [$queue, $output] = $this->rewrite('{"images":["wp-content\/a.png"]}');

        self::assertSame('{"images":[".\/wp-content\/a.png"]}', $output);
        self::assertSame(['2013/01/11/page-a/wp-content/a.png'], $queue->outputPaths());
    }

    /**
     * Array elements under a src key inherit the URL context too, so each
     * element is rewritten and queued like the scalar src pins.
     */
    public function testSrcArrayElementsInheritUrlKeyContext(): void
    {
        [$queue, $output] = $this->rewrite('{"src":["wp-content\/b.png"]}');

        self::assertSame('{"src":[".\/wp-content\/b.png"]}', $output);
        self::assertSame(['2013/01/11/page-a/wp-content/b.png'], $queue->outputPaths());
    }

    /**
     * The url key plural also names URL-bearing fields: each bare relative
     * path inside an urls array is rewritten and queued, mirroring the
     * scalar url and images pins.
     */
    public function testUrlsArrayElementsInheritUrlKeyContext(): void
    {
        [$queue, $output] = $this->rewrite('{"urls":["wp-content\/a.png"]}');

        self::assertSame('{"urls":[".\/wp-content\/a.png"]}', $output);
        self::assertSame(['2013/01/11/page-a/wp-content/a.png'], $queue->outputPaths());
    }

    /**
     * The icon key plural also names URL-bearing fields: a bare relative path
     * under an icons key is rewritten and queued like the icon scalar pins.
     */
    public function testIconsScalarValueUnderUrlKeyIsRewritten(): void
    {
        [$queue, $output] = $this->rewrite('{"icons":"wp-content\/b.png"}');

        self::assertSame('{"icons":".\/wp-content\/b.png"}', $output);
        self::assertSame(['2013/01/11/page-a/wp-content/b.png'], $queue->outputPaths());
    }

    /**
     * The logo key plural also names URL-bearing fields: each asset path in a
     * logos array is rewritten and queued like the logo scalar pins.
     */
    public function testLogosArrayElementsInheritUrlKeyContext(): void
    {
        [$queue, $output] = $this->rewrite('{"logos":["assets\/logo.svg"]}');

        self::assertSame('{"logos":[".\/assets\/logo.svg"]}', $output);
        self::assertSame(['2013/01/11/page-a/assets/logo.svg'], $queue->outputPaths());
    }

    /**
     * The src key plural also names URL-bearing fields: each bare relative
     * path inside a srcs array is rewritten and queued like the src scalar
     * and array pins.
     */
    public function testSrcsArrayElementsInheritUrlKeyContext(): void
    {
        [$queue, $output] = $this->rewrite('{"srcs":["img\/c.png"]}');

        self::assertSame('{"srcs":[".\/img\/c.png"]}', $output);
        self::assertSame(['2013/01/11/page-a/img/c.png'], $queue->outputPaths());
    }

    /**
     * "aspects" is not a URL-context token: whole-token matching must not let
     * the singular "aspect" pin leak into the plural, so the date-like value
     * survives verbatim (no rewrite, no capture queue).
     */
    public function testAspectsValueUnderNonUrlKeyIsPreserved(): void
    {
        [$queue, $output] = $this->rewrite('{"aspects":"2024\/01\/15"}');

        self::assertSame('{"aspects":"2024\/01\/15"}', $output);
        self::assertCount(0, $queue);
    }

    /**
     * @return array{0: CapturingQueueCollector, 1: string}
     */
    private function rewrite(string $value): array
    {
        $queue = new CapturingQueueCollector();
        $document = new Url('https', 'example.com', null, '/2013/01/11/page-a/', '');
        $context = new RewriteContext($document, Origin::fromUrl($document), $queue);

        return [$queue, $this->rewriter->rewrite($value, $context)];
    }
}
