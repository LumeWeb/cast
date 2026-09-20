<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\AssetExtensions;
use LumeWeb\Cast\Export\Url;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\UrlClassifier;
use LumeWeb\Cast\Export\WorkItemKind;
use PHPUnit\Framework\TestCase;

final class UrlClassifierTest extends TestCase
{
    private UrlClassifier $classifier;
    private AssetExtensions $defaults;
    private UrlCanonicalizer $canonicalizer;

    protected function setUp(): void
    {
        $this->classifier = new UrlClassifier();
        $this->defaults = new AssetExtensions();
        $this->canonicalizer = new UrlCanonicalizer();
    }

    public function testDefaultAllowlistContainsCatalogAssets(): void
    {
        foreach (['css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'json', 'xml', 'map', 'pdf', 'mp3', 'mp4', 'webm'] as $ext) {
            self::assertTrue($this->defaults->contains($ext), "expected {$ext} to be an asset extension");
        }
    }

    public function testContainsIsCaseInsensitive(): void
    {
        self::assertTrue($this->defaults->contains('CSS'));
        self::assertTrue($this->defaults->contains('.png'));
    }

    public function testPhpIsNeverAnAssetExtension(): void
    {
        self::assertFalse($this->defaults->contains('php'));
        self::assertFalse($this->defaults->contains('phtml'));

        $extensions = new AssetExtensions(['php', 'phtml', 'css']);
        self::assertFalse($extensions->contains('php'));
        self::assertFalse($extensions->contains('phtml'));
        self::assertTrue($extensions->contains('css'));
    }

    public function testAssetByAllowlistedExtension(): void
    {
        $url = $this->canonicalize('https://example.com/wp-content/themes/x/style.css');

        self::assertSame(WorkItemKind::Asset, $this->classifier->classify($url, $this->defaults));
    }

    public function testExtensionlessPathIsAPage(): void
    {
        foreach (['https://example.com/', 'https://example.com/about/', 'https://example.com/2013/01/11/page-a/'] as $raw) {
            self::assertSame(
                WorkItemKind::Page,
                $this->classifier->classify($this->canonicalize($raw), $this->defaults),
                $raw
            );
        }
    }

    public function testFixedTextFiles(): void
    {
        foreach (['robots.txt', '_redirects', '_headers', 'llms.txt'] as $name) {
            self::assertSame(
                WorkItemKind::Text,
                $this->classifier->classify($this->canonicalize('https://example.com/' . $name), $this->defaults),
                $name
            );
        }
    }

    public function testFaviconIsAnAsset(): void
    {
        $url = $this->canonicalize('https://example.com/favicon.ico');

        self::assertSame(WorkItemKind::Asset, $this->classifier->classify($url, $this->defaults));
    }

    private function canonicalize(string $raw): Url
    {
        return $this->canonicalizer->canonicalize($raw);
    }
}
