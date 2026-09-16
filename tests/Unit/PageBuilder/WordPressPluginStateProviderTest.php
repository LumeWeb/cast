<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\PageBuilder;

use LumeWeb\Cast\PageBuilder\WordPressPluginStateProvider;
use PHPUnit\Framework\TestCase;

/**
 * The real plugin-state adapter, exercised against shimmed WordPress
 * get_plugins()/is_plugin_active() so the basename derivation is pinned to the
 * actual filesystem basenames core reports.
 */
final class WordPressPluginStateProviderTest extends TestCase
{
    private WordPressPluginStateProvider $provider;

    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_plugins'] = [];
        $GLOBALS['lumeweb_cast_active_plugins'] = [];
        $this->provider = new WordPressPluginStateProvider();
    }

    public function testBasenameMatchesInstalledPluginByDirectory(): void
    {
        $GLOBALS['lumeweb_cast_plugins'] = [
            'akismet/akismet.php' => ['Name' => 'Akismet'],
            'brizy/brizy.php' => ['Name' => 'Brizy'],
            'generateblocks/generateblocks.php' => ['Name' => 'GenerateBlocks'],
        ];

        self::assertSame('brizy/brizy.php', $this->provider->basename('brizy'));
        self::assertSame('generateblocks/generateblocks.php', $this->provider->basename('generateblocks'));
        self::assertSame('akismet/akismet.php', $this->provider->basename('akismet'));
    }

    public function testBasenameDoesNotMatchSimilarSlugPrefix(): void
    {
        $GLOBALS['lumeweb_cast_plugins'] = [
            'brizy/brizy.php' => ['Name' => 'Brizy'],
            'brizy-premium/brizy-premium.php' => ['Name' => 'Brizy Premium'],
        ];

        self::assertSame('brizy/brizy.php', $this->provider->basename('brizy'));
        self::assertSame('brizy-premium/brizy-premium.php', $this->provider->basename('brizy-premium'));
    }

    public function testIsInstalledFalseWhenPluginAbsent(): void
    {
        $GLOBALS['lumeweb_cast_plugins'] = ['hello-dolly/hello.php' => ['Name' => 'Hello Dolly']];

        self::assertFalse($this->provider->isInstalled('brizy'));
        self::assertNull($this->provider->basename('brizy'));
        self::assertFalse($this->provider->isInstalled(''));
    }

    public function testIsInstalledTrueOnlyForKnownDirectory(): void
    {
        $GLOBALS['lumeweb_cast_plugins'] = ['brizy/brizy.php' => ['Name' => 'Brizy']];

        self::assertTrue($this->provider->isInstalled('brizy'));
        self::assertFalse($this->provider->isInstalled('elementor'));
    }

    public function testIsActiveOnlyWhenExactBasenameIsActive(): void
    {
        $GLOBALS['lumeweb_cast_plugins'] = [
            'brizy/brizy.php' => ['Name' => 'Brizy'],
            'generateblocks/generateblocks.php' => ['Name' => 'GenerateBlocks'],
        ];
        $GLOBALS['lumeweb_cast_active_plugins'] = ['brizy/brizy.php'];

        self::assertTrue($this->provider->isActive('brizy'));
        self::assertFalse($this->provider->isActive('generateblocks'));
        self::assertFalse($this->provider->isActive('elementor'));
    }

    public function testBasenameIsNullWhenPluginDirectoryEmpty(): void
    {
        $GLOBALS['lumeweb_cast_plugins'] = [];

        self::assertNull($this->provider->basename('brizy'));
        self::assertFalse($this->provider->isActive('brizy'));
    }
}
