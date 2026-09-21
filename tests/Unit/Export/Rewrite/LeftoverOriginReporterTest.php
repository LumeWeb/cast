<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export\Rewrite;

use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\Rewrite\ArrayWarningCollector;
use LumeWeb\Cast\Export\Rewrite\LeftoverOriginReporter;
use LumeWeb\Cast\Export\Rewrite\RewriteContext;
use LumeWeb\Cast\Export\Rewrite\WarningCollector;
use LumeWeb\Cast\Export\Url;
use PHPUnit\Framework\TestCase;

/**
 * Offline mode can never recompute ../ depth for a
 * host-swap, so an origin URL that survives parse-and-replace is reported as a
 * manifest warning, never smashed. The reporter only scans; it never rewrites.
 */
final class LeftoverOriginReporterTest extends TestCase
{
    private LeftoverOriginReporter $reporter;
    private ArrayWarningCollector $warnings;
    private RewriteContext $context;

    protected function setUp(): void
    {
        $this->reporter = new LeftoverOriginReporter();
        $this->warnings = new ArrayWarningCollector();
        $document = new Url('https', 'example.com', null, '/2013/01/11/page-a/', '');
        $this->context = new RewriteContext(
            $document,
            Origin::fromUrl($document),
            new CapturingQueueCollector(),
        );
    }

    public function testCleanContentRecordsNoWarningAndIsReturnedUnchanged(): void
    {
        $content = './../../../../index.html';

        $out = $this->reporter->report($content, $this->context, $this->warnings);

        self::assertSame($content, $out);
        self::assertFalse($this->warnings->hasLeftoverOrigin());
    }

    public function testAbsoluteOriginUrlRecordsLeftoverWarning(): void
    {
        $this->reporter->report(
            '<script>var x = "https://example.com/rest/api";</script>',
            $this->context,
            $this->warnings
        );

        self::assertTrue($this->warnings->hasLeftoverOrigin());
        self::assertCount(1, $this->warnings->all());
        self::assertSame(WarningCollector::ORIGIN_LEFTOVER, $this->warnings->all()[0]['category']);
    }

    public function testProtocolRelativeOriginUrlRecordsLeftoverWarning(): void
    {
        $this->reporter->report('<img alt="//example.com/logo.png">', $this->context, $this->warnings);

        self::assertTrue($this->warnings->hasLeftoverOrigin());
    }

    public function testJsonEscapedOriginUrlRecordsLeftoverWarning(): void
    {
        $this->reporter->report('{"url":"https:\\/\\/example.com\\/api"}', $this->context, $this->warnings);

        self::assertTrue($this->warnings->hasLeftoverOrigin());
    }

    public function testPercentEncodedOriginUrlIsNotYetCoveredByThisBoundary(): void
    {
        // %3A%2F%2F remains a known gap (encoded last pass); the reporter
        // must not crash on it and must simply not flag it here.
        $this->reporter->report('https%3A%2F%2Fexample.com%2Fabout%2F', $this->context, $this->warnings);

        self::assertFalse($this->warnings->hasLeftoverOrigin());
    }

    public function testExternalHostIsNotReported(): void
    {
        $this->reporter->report('https://res.cloudinary.com/demo/f_auto,q_auto/b.jpg', $this->context, $this->warnings);

        self::assertFalse($this->warnings->hasLeftoverOrigin());
    }

    public function testOriginHostMentionInPlainTextIsReported(): void
    {
        $this->reporter->report('seen at https://example.com somewhere', $this->context, $this->warnings);

        self::assertTrue($this->warnings->hasLeftoverOrigin());
    }
}
