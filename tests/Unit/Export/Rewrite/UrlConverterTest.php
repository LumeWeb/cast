<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export\Rewrite;

use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\Rewrite\UrlConverter;
use LumeWeb\Cast\Export\Rewrite\UrlConversion;
use LumeWeb\Cast\Export\Url;
use PHPUnit\Framework\TestCase;

final class UrlConverterTest extends TestCase
{
    private UrlConverter $converter;
    private Url $document;
    private Origin $origin;

    protected function setUp(): void
    {
        $this->converter = new UrlConverter();
        $this->document = new Url('https', 'example.com', null, '/2013/01/11/page-a/', '');
        $this->origin = Origin::fromUrl($this->document);
    }

    private function convert(string $raw): UrlConversion
    {
        return $this->converter->convert($raw, $this->document, $this->origin);
    }

    public function testK5OfflineSibling(): void
    {
        $conversion = $this->convert('/2013/01/10/page-b/');

        self::assertSame('./../../10/page-b/index.html', $conversion->rewritten());
        self::assertNotNull($conversion->queued());
        self::assertSame('2013/01/10/page-b/index.html', $conversion->queued()->outputPath());
    }

    public function testK5OfflineHome(): void
    {
        $conversion = $this->convert('/');

        self::assertSame('./../../../../index.html', $conversion->rewritten());
    }

    public function testK5OfflineRootCss(): void
    {
        $conversion = $this->convert('/wp-content/themes/x/style.css');

        self::assertSame('./../../../../wp-content/themes/x/style.css', $conversion->rewritten());
        self::assertNotNull($conversion->queued());
        self::assertSame('asset', $conversion->queued()->kind()->value);
        self::assertSame('wp-content/themes/x/style.css', $conversion->queued()->outputPath());
    }

    public function testK5ProtocolRelativeIsTreatedAsOrigin(): void
    {
        $conversion = $this->convert('//example.com/wp-content/themes/x/style.css');

        self::assertSame('./../../../../wp-content/themes/x/style.css', $conversion->rewritten());
        self::assertNotNull($conversion->queued());
    }

    public function testK5HashOnlyIsUnchangedAndUnqueued(): void
    {
        $conversion = $this->convert('#section-2');

        self::assertTrue($conversion->unchanged());
        self::assertSame('#section-2', $conversion->rewritten());
        self::assertNull($conversion->queued());
    }

    public function testNonWebSchemesAreUnchangedAndUnqueued(): void
    {
        foreach (['data:image/png;base64,AAA', 'javascript:void(0)', 'mailto:hi@example.com', 'tel:+1555'] as $raw) {
            $conversion = $this->convert($raw);
            self::assertTrue($conversion->unchanged(), "{$raw} must stay unchanged");
            self::assertNull($conversion->queued());
        }
    }

    public function testExternalUrlIsUnchangedAndUnqueued(): void
    {
        $conversion = $this->convert('https://res.cloudinary.com/demo/f_auto,q_auto/b.jpg');

        self::assertTrue($conversion->unchanged());
        self::assertSame('https://res.cloudinary.com/demo/f_auto,q_auto/b.jpg', $conversion->rewritten());
        self::assertNull($conversion->queued());
    }

    public function testAbsoluteLocalPageConvertsOffline(): void
    {
        $conversion = $this->convert('https://example.com/about/');

        self::assertSame('./../../../../about/index.html', $conversion->rewritten());
        self::assertNotNull($conversion->queued());
    }

    public function testFragmentOnLocalTargetIsPreserved(): void
    {
        $conversion = $this->convert('/about/#team');

        self::assertSame('./../../../../about/index.html#team', $conversion->rewritten());
    }

    public function testAssetQueryDoesNotCreateAFile(): void
    {
        $conversion = $this->convert('/wp-content/themes/x/style.css?ver=6.7.1');

        self::assertSame('./../../../../wp-content/themes/x/style.css', $conversion->rewritten());
        self::assertNotNull($conversion->queued());
        self::assertSame('wp-content/themes/x/style.css', $conversion->queued()->outputPath());
    }

    public function testPageOpenGraphUrlConvertsLikeAnyPage(): void
    {
        // og:url is an absolute local URL; same offline math applies.
        $conversion = $this->convert('https://example.com/2013/01/10/page-b/');

        self::assertSame('./../../10/page-b/index.html', $conversion->rewritten());
    }

    public function testMalformedRelativeValueIsLeftUnchanged(): void
    {
        // A value the resolver cannot parse should not crash conversion.
        $conversion = $this->convert('');

        self::assertTrue($conversion->unchanged());
    }
}
