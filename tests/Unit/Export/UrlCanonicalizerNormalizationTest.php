<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\InvalidUrl;
use LumeWeb\Cast\Export\Url;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\UrlRejection;
use PHPUnit\Framework\TestCase;

final class UrlCanonicalizerNormalizationTest extends TestCase
{
    private UrlCanonicalizer $canonicalizer;

    protected function setUp(): void
    {
        $this->canonicalizer = new UrlCanonicalizer();
    }

    public function testLowercasesSchemeAndHost(): void
    {
        self::assertSame(
            'https://example.com/about',
            (string) $this->canonicalize('HTTPS://EXAMPLE.COM/about')
        );
    }

    public function testStripsFragment(): void
    {
        $url = $this->canonicalize('https://example.com/about/#team');

        self::assertSame('https://example.com/about/', (string) $url);
        self::assertSame('/about/', $url->path());
    }

    public function testNormalizesEffectivePorts(): void
    {
        self::assertSame('http://example.com/a', (string) $this->canonicalize('http://example.com:80/a'));
        self::assertSame('https://example.com/a', (string) $this->canonicalize('https://example.com:443/a'));
        self::assertSame('https://example.com:8443/a', (string) $this->canonicalize('https://example.com:8443/a'));
        self::assertNull($this->canonicalize('https://example.com:443/a')->port());
        self::assertSame(8443, $this->canonicalize('https://example.com:8443/a')->port());
    }

    public function testEmptyPathBecomesRootSlash(): void
    {
        self::assertSame('https://example.com/', (string) $this->canonicalize('https://example.com'));
    }

    public function testSortsQueryPairsLexicographically(): void
    {
        $url = $this->canonicalize('https://example.com/?s=hello&b=2&a=1');

        self::assertTrue($url->hasQuery());
        self::assertSame('a=1&b=2&s=hello', $url->query());
    }

    public function testRejectsRelativeUrlWithNoScheme(): void
    {
        try {
            $this->canonicalize('/about/');
            self::fail('expected relative rejection');
        } catch (InvalidUrl $e) {
            self::assertSame(UrlRejection::Relative, $e->rejection);
        }
    }

    private function canonicalize(string $raw): Url
    {
        return $this->canonicalizer->canonicalize($raw);
    }
}
