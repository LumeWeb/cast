<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\DirectoryArtifactTree;
use PHPUnit\Framework\TestCase;

final class DirectoryArtifactTreeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cast-tree-' . bin2hex(random_bytes(6));
        $this->makeTree($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testListsSortedNormalizedRelativePaths(): void
    {
        $tree = new DirectoryArtifactTree($this->root);

        self::assertSame([
            'about/index.html',
            'index.html',
            'wp-content/uploads/2024/01/photo.jpg',
            'wp-content/uploads/theme/style.css',
        ], $tree->paths());
    }

    public function testExistsAndReadText(): void
    {
        $tree = new DirectoryArtifactTree($this->root);

        self::assertTrue($tree->exists('index.html'));
        self::assertSame('<html>root</html>', $tree->readText('index.html'));
        self::assertFalse($tree->exists('missing.html'));
        self::assertNull($tree->readText('missing.html'));
    }

    public function testIsTextLikeBranchesByExtension(): void
    {
        $tree = new DirectoryArtifactTree($this->root);

        self::assertTrue($tree->isTextLike('index.html'));
        self::assertTrue($tree->isTextLike('wp-content/uploads/theme/style.css'));
        self::assertFalse($tree->isTextLike('wp-content/uploads/2024/01/photo.jpg'));
    }

    public function testSymlinkAppearsInListingButReadsOutsideJailUsingRealpath(): void
    {
        $outside = sys_get_temp_dir() . '/cast-tree-out-' . bin2hex(random_bytes(6)) . '.txt';
        file_put_contents($outside, 'OUTSIDE');
        @symlink($outside, $this->root . '/leak.txt');

        $tree = new DirectoryArtifactTree($this->root);

        try {
            // The name is listed so the packer's realpath jail can reject it,
            // but the tree itself refuses to serve content that resolves
            // outside the jail root.
            self::assertContains('leak.txt', $tree->paths());
            self::assertFalse($tree->exists('leak.txt'));
            self::assertNull($tree->readText('leak.txt'));
        } finally {
            @unlink($outside);
        }
    }

    public function testReadTextOnDirectoryReturnsNull(): void
    {
        $tree = new DirectoryArtifactTree($this->root);

        self::assertNull($tree->readText('about'));
    }

    public function testMissingRootYieldsEmptyTree(): void
    {
        $tree = new DirectoryArtifactTree($this->root . '/missing');

        self::assertSame([], $tree->paths());
        self::assertFalse($tree->exists('index.html'));
    }

    private function makeTree(string $root): void
    {
        mkdir($root . '/about', 0777, true);
        mkdir($root . '/wp-content/uploads/2024/01', 0777, true);
        mkdir($root . '/wp-content/uploads/theme', 0777, true);
        file_put_contents($root . '/index.html', '<html>root</html>');
        file_put_contents($root . '/about/index.html', '<html>about</html>');
        file_put_contents($root . '/wp-content/uploads/2024/01/photo.jpg', 'JPG');
        file_put_contents($root . '/wp-content/uploads/theme/style.css', 'body{}');
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
