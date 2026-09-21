<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\WordPressAssetRoots;
use PHPUnit\Framework\TestCase;

final class WordPressAssetRootsTest extends TestCase
{
    public function testMapsUploadsPrefix(): void
    {
        self::assertSame(
            ['root' => '/srv/content/uploads', 'relative' => '2024/01/photo.jpg'],
            $this->roots()->map('/wp-content/uploads/2024/01/photo.jpg'),
        );
    }

    public function testMapsPluginsPrefix(): void
    {
        self::assertSame(
            ['root' => '/srv/content/plugins', 'relative' => 'acme/style.css'],
            $this->roots()->map('/wp-content/plugins/acme/style.css'),
        );
    }

    public function testMapsThemesPrefix(): void
    {
        self::assertSame(
            ['root' => '/srv/content/themes', 'relative' => 'twentytwentyfour/theme.css'],
            $this->roots()->map('/wp-content/themes/twentytwentyfour/theme.css'),
        );
    }

    public function testMapsGenericContentPrefix(): void
    {
        self::assertSame(
            ['root' => '/srv/content', 'relative' => 'misc/font.woff'],
            $this->roots()->map('/wp-content/misc/font.woff'),
        );
    }

    public function testMapsWpIncludesIntoAbspath(): void
    {
        self::assertSame(
            ['root' => '/srv/wp-includes', 'relative' => 'js/wp-embed.min.js'],
            $this->roots()->map('/wp-includes/js/wp-embed.min.js'),
        );
    }

    public function testSubdirectoryInstallStillMapsBySuffix(): void
    {
        self::assertSame(
            ['root' => '/srv/content/uploads', 'relative' => 'x.png'],
            $this->roots()->map('/blog/wp-content/uploads/x.png'),
        );
    }

    public function testUnmappableWebPathReturnsNull(): void
    {
        self::assertNull($this->roots()->map('/about'));
        self::assertNull($this->roots()->map('/favicon.ico'));
    }

    public function testRootOnlyPathReturnsNull(): void
    {
        self::assertNull($this->roots()->map('/wp-content/uploads'));
        self::assertNull($this->roots()->map('/wp-content/uploads/'));
    }

    public function testUppercasedPrefixDoesNotMap(): void
    {
        self::assertNull($this->roots()->map('/WP-CONTENT/UPLOADS/2024/01/a.jpg'));
    }

    private function roots(): WordPressAssetRoots
    {
        return new WordPressAssetRoots(
            '/srv/content',
            '/srv/content/uploads',
            '/srv/content/plugins',
            '/srv/content/themes',
            '/srv',
        );
    }
}
