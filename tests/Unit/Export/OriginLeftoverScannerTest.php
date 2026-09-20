<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\OriginLeftoverScanner;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use PHPUnit\Framework\TestCase;

final class OriginLeftoverScannerTest extends TestCase
{
    private OriginLeftoverScanner $scanner;

    protected function setUp(): void
    {
        $origin = Origin::fromUrl((new UrlCanonicalizer())->canonicalize('https://example.com/'));
        $this->scanner = new OriginLeftoverScanner($origin);
    }

    public function testPlainAbsoluteFormIsCaught(): void
    {
        self::assertTrue($this->scanner->hasLeftover('See https://example.com/wp-content/x.jpg now'));
        self::assertSame(2, $this->scanner->count('See https://example.com/ and http://example.com/a'));
    }

    public function testProtocolRelativeFormIsCaught(): void
    {
        self::assertTrue($this->scanner->hasLeftover('src="//example.com/a.jpg"'));
    }

    public function testJsonEscapedFormIsCaught(): void
    {
        self::assertTrue($this->scanner->hasLeftover('"url":"https:\\/\\/example.com\\/a.jpg"'));
    }

    public function testPercentEncodedFormIsCaught(): void
    {
        self::assertTrue($this->scanner->hasLeftover('href="https%3A%2F%2Fexample.com%2Fa"'));
        self::assertTrue($this->scanner->hasLeftover('href="%2F%2Fexample.com%2Fa"'));
    }

    public function testCountsMultipleOccurrences(): void
    {
        $content = 'https://example.com/a https://example.com/b //example.com/c';

        self::assertSame(3, $this->scanner->count($content));
    }

    public function testOffOriginHostIsNotLeftover(): void
    {
        self::assertFalse($this->scanner->hasLeftover('https://other.test/example.com/path'));
    }

    public function testCleanContentIsNotLeftover(): void
    {
        self::assertFalse($this->scanner->hasLeftover('<a href="../about/index.html">about</a>'));
        self::assertSame(0, $this->scanner->count('no origin here'));
    }

    public function testHostIsCaseInsensitive(): void
    {
        self::assertTrue($this->scanner->hasLeftover('https://EXAMPLE.COM/a'));
    }
}
