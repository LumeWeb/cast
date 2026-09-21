<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\OutputPathResolver;
use LumeWeb\Cast\Export\Url;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\WorkItemKind;
use PHPUnit\Framework\TestCase;

final class OutputPathResolverTest extends TestCase
{
    private OutputPathResolver $resolver;
    private UrlCanonicalizer $canonicalizer;

    protected function setUp(): void
    {
        $this->resolver = new OutputPathResolver();
        $this->canonicalizer = new UrlCanonicalizer();
    }

    public function testExtensionlessPagesGetIndexHtml(): void
    {
        self::assertSame('index.html', $this->resolve('https://example.com/'));
        self::assertSame('about/index.html', $this->resolve('https://example.com/about/'));
        self::assertSame('about/index.html', $this->resolve('https://example.com/about'));
        self::assertSame('2013/01/11/page-a/index.html', $this->resolve('https://example.com/2013/01/11/page-a/'));
    }

    public function testQueryPagesUseHashedQsubdirectory(): void
    {
        $hash = substr(md5('/?s=hello'), 0, 12);

        self::assertSame("__qs/{$hash}/index.html", $this->resolve('https://example.com/?s=hello'));
    }

    public function testSortedQuerySharesOneStoragePath(): void
    {
        $hash = substr(md5('/?a=1&b=2'), 0, 12);

        self::assertSame("__qs/{$hash}/index.html", $this->resolve('https://example.com/?b=2&a=1'));
        self::assertSame("__qs/{$hash}/index.html", $this->resolve('https://example.com/?a=1&b=2'));
    }

    public function testFixedFilesKeepTheirName(): void
    {
        self::assertSame('robots.txt', $this->resolve('https://example.com/robots.txt', WorkItemKind::Text));
        self::assertSame('favicon.ico', $this->resolve('https://example.com/favicon.ico', WorkItemKind::Asset));
        self::assertSame('_redirects', $this->resolve('https://example.com/_redirects', WorkItemKind::Text));
        self::assertSame('llms.txt', $this->resolve('https://example.com/llms.txt', WorkItemKind::Text));
    }

    public function testAssetsRetainTheirPath(): void
    {
        self::assertSame(
            'wp-content/themes/x/style.css',
            $this->resolve('https://example.com/wp-content/themes/x/style.css', WorkItemKind::Asset)
        );
        self::assertSame(
            'wp-content/uploads/2024/01/photo.jpg',
            $this->resolve('https://example.com/wp-content/uploads/2024/01/photo.jpg', WorkItemKind::Asset)
        );
    }

    private function resolve(string $raw, WorkItemKind $kind = WorkItemKind::Page): string
    {
        $url = $this->canonicalizer->canonicalize($raw);

        return $this->resolver->resolve($url, $kind);
    }
}
