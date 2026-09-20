<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\JailedDiskAssetSource;
use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemFactory;
use PHPUnit\Framework\TestCase;

final class JailedDiskAssetSourceTest extends TestCase
{
    private string $root;
    private string $outsideFile;
    private WorkItemFactory $factory;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cast-jail-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/wp-content/uploads', 0777, true);

        $this->outsideFile = sys_get_temp_dir() . '/cast-jail-out-' . bin2hex(random_bytes(6)) . '.txt';
        file_put_contents($this->outsideFile, 'OUTSIDE');

        $this->factory = new WorkItemFactory();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        @unlink($this->outsideFile);
    }

    public function testReadsAssetInsideJail(): void
    {
        file_put_contents($this->root . '/wp-content/uploads/photo.jpg', 'JPG-DATA');

        $body = $this->source()->read($this->item('https://example.com/wp-content/uploads/photo.jpg'));

        self::assertNotNull($body);
        self::assertSame('JPG-DATA', $body->contents());
        self::assertSame(8, $body->size());
    }

    public function testMissingFileFallsBackToNull(): void
    {
        self::assertNull($this->source()->read($this->item('https://example.com/wp-content/uploads/nope.jpg')));
    }

    public function testDotSegmentTraversalNeverEscapes(): void
    {
        // The file exists outside the jail root; a '..' path must not reach it.
        $traversal = '/wp-content/../../' . basename($this->outsideFile);

        self::assertNull($this->source()->read($this->item('https://example.com' . $traversal)));
    }

    public function testSymlinkEscapeIsBlockedByRealpathJail(): void
    {
        file_put_contents($this->root . '/wp-content/uploads/photo.jpg', 'INSIDE');
        @symlink($this->outsideFile, $this->root . '/wp-content/uploads/link.jpg');

        self::assertNull($this->source()->read($this->item('https://example.com/wp-content/uploads/link.jpg')));
    }

    public function testDirectoryIsNotReadableAsAssetBody(): void
    {
        self::assertNull($this->source()->read($this->item('https://example.com/wp-content/uploads')));
    }

    private function source(): JailedDiskAssetSource
    {
        return new JailedDiskAssetSource($this->root);
    }

    private function item(string $raw): WorkItem
    {
        return $this->factory->fromString($raw);
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
