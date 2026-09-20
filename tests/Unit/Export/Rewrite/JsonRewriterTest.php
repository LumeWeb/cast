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
