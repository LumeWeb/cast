<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\Url;
use LumeWeb\Cast\Export\WordPressAssetFileSystem;
use LumeWeb\Cast\Export\WordPressAssetRoots;
use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Export\WorkItemKind;
use PHPUnit\Framework\TestCase;

final class WordPressAssetFileSystemTest extends TestCase
{
    private string $root;
    private string $contentDir;
    private string $uploadsDir;
    private WordPressAssetRoots $roots;
    private WorkItemFactory $factory;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cast-wpaths-' . bin2hex(random_bytes(6));
        $this->contentDir = $this->root . '/wp-content';
        $this->uploadsDir = $this->contentDir . '/uploads';
        // Every fixture file's parent directory must exist before
        // file_put_contents() can seed it (it never creates parents).
        foreach ([$this->uploadsDir, $this->contentDir . '/plugins', $this->contentDir . '/themes'] as $dir) {
            mkdir($dir, 0777, true);
        }
        foreach (
            [
                $this->uploadsDir . '/2024/01',
                $this->contentDir . '/plugins/acme',
                $this->contentDir . '/themes/twentytwentyfour',
                $this->contentDir . '/misc',
                $this->root . '/wp-includes/js',
            ] as $dir
        ) {
            mkdir($dir, 0777, true);
        }

        $this->roots = new WordPressAssetRoots(
            $this->contentDir,
            $this->uploadsDir,
            $this->contentDir . '/plugins',
            $this->contentDir . '/themes',
            $this->root,
        );
        $this->factory = new WorkItemFactory();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testReadsFilesFromEveryMappedRoot(): void
    {
        file_put_contents($this->uploadsDir . '/2024/01/photo.jpg', 'UPLOAD');
        file_put_contents($this->contentDir . '/plugins/acme/style.css', 'PLUGIN');
        file_put_contents($this->contentDir . '/themes/twentytwentyfour/theme.css', 'THEME');
        file_put_contents($this->contentDir . '/misc/font.woff', 'CONTENT');
        file_put_contents($this->root . '/wp-includes/js/wp-embed.min.js', 'INCLUDES');

        self::assertSame('UPLOAD', $this->source()->read($this->item('/wp-content/uploads/2024/01/photo.jpg'))?->contents());
        self::assertSame('PLUGIN', $this->source()->read($this->item('/wp-content/plugins/acme/style.css'))?->contents());
        self::assertSame('THEME', $this->source()->read($this->item('/wp-content/themes/twentytwentyfour/theme.css'))?->contents());
        self::assertSame('CONTENT', $this->source()->read($this->item('/wp-content/misc/font.woff'))?->contents());
        self::assertSame('INCLUDES', $this->source()->read($this->item('/wp-includes/js/wp-embed.min.js'))?->contents());
    }

    public function testMissingFileFallsBackToNull(): void
    {
        self::assertNull($this->source()->read($this->item('/wp-content/uploads/nope.jpg')));
    }

    public function testUnmappableWebPathReturnsNull(): void
    {
        self::assertNull($this->source()->read($this->item('/api/thing.json')));
        self::assertNull($this->source()->read($this->item('/favicon.ico')));
    }

    public function testEncodedSeparatorsAreRejected(): void
    {
        self::assertNull($this->source()->read($this->item('/wp-content/uploads/..%2fsecret')));
        self::assertNull($this->source()->read($this->item('/wp-content/uploads/a%5cb.jpg')));
    }

    public function testEncodedNulAndControlAreRejected(): void
    {
        self::assertNull($this->source()->read($this->item('/wp-content/uploads/a%00b.jpg')));
        self::assertNull($this->source()->read($this->item('/wp-content/uploads/a%01b.jpg')));
    }

    public function testRawBackslashPathIsRejected(): void
    {
        self::assertNull($this->source()->read($this->rawItem('/wp-content/uploads/a\\b.jpg')));
    }

    public function testDotSegmentTraversalIsRejected(): void
    {
        self::assertNull($this->source()->read($this->item('/wp-content/uploads/../../../etc/passwd')));
        self::assertNull($this->source()->read($this->rawItem('/wp-content/uploads/../outside.bin')));
    }

    public function testSymlinkEscapeIsBlockedByRealpathJail(): void
    {
        $outside = sys_get_temp_dir() . '/cast-wpaths-out-' . bin2hex(random_bytes(6)) . '.txt';
        file_put_contents($outside, 'OUTSIDE');
        @symlink($outside, $this->uploadsDir . '/link.jpg');

        $body = $this->source()->read($this->item('/wp-content/uploads/link.jpg'));

        self::assertNull($body);
        @unlink($outside);
    }

    public function testExportWorkDirectoryIsExcluded(): void
    {
        $workDir = $this->uploadsDir . '/cast-run-abc';
        mkdir($workDir, 0777, true);
        file_put_contents($workDir . '/secret.css', 'SECRET');

        $source = new WordPressAssetFileSystem($this->roots, $workDir);

        self::assertNull($source->read($this->item('/wp-content/uploads/cast-run-abc/secret.css')));
    }

    public function testReadIsASnapshotCopy(): void
    {
        $file = $this->uploadsDir . '/snap.txt';
        file_put_contents($file, 'V1');

        $body = $this->source()->read($this->item('/wp-content/uploads/snap.txt'));

        file_put_contents($file, 'V2');

        self::assertSame('V1', $body?->contents());
    }

    private function source(): WordPressAssetFileSystem
    {
        return new WordPressAssetFileSystem($this->roots);
    }

    private function item(string $path): WorkItem
    {
        return $this->factory->fromString('https://example.com' . $path);
    }

    /**
     * Builds an asset work item straight from a raw URL path, bypassing the
     * canonicalizer (which would reject backslashes/NUL before it ever reaches
     * the jail) so the source's own defensive rejection can be exercised.
     */
    private function rawItem(string $urlPath): WorkItem
    {
        $url = new Url('https', 'example.com', null, $urlPath, '');
        $identity = 'https://example.com' . $urlPath;

        return new WorkItem($url, WorkItemKind::Asset, $identity, md5($identity), 'asset.bin');
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
