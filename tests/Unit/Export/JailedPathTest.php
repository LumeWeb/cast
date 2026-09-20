<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\JailedPath;
use PHPUnit\Framework\TestCase;

final class JailedPathTest extends TestCase
{
    private string $root;
    private string $outsideFile;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cast-jailpath-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/sub', 0777, true);

        $this->outsideFile = sys_get_temp_dir() . '/cast-jailpath-out-' . bin2hex(random_bytes(6)) . '.txt';
        file_put_contents($this->outsideFile, 'OUTSIDE');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        @unlink($this->outsideFile);
    }

    public function testResolvesSafeRelativePath(): void
    {
        file_put_contents($this->root . '/sub/photo.jpg', 'JPG-DATA');

        self::assertSame(
            realpath($this->root . '/sub/photo.jpg'),
            JailedPath::resolve('sub/photo.jpg', $this->root),
        );
    }

    public function testResolvesUrlEncodedSafeCharacters(): void
    {
        file_put_contents($this->root . '/sub/my photo.jpg', 'SPACE-DATA');

        self::assertSame(
            realpath($this->root . '/sub/my photo.jpg'),
            JailedPath::resolve('sub/my%20photo.jpg', $this->root),
        );
    }

    public function testEncodedSeparatorsAreRejected(): void
    {
        self::assertNull(JailedPath::resolve('sub%2fphoto.jpg', $this->root));
        self::assertNull(JailedPath::resolve('sub%5cphoto.jpg', $this->root));
        self::assertNull(JailedPath::resolve('..%2f..%2fetc%2fpasswd', $this->root));
    }

    public function testEncodedDotDotIsRejected(): void
    {
        self::assertNull(JailedPath::resolve('%2e%2e/secret', $this->root));
        self::assertNull(JailedPath::resolve('..%2e%2esecret', $this->root));
    }

    public function testBackslashIsRejected(): void
    {
        self::assertNull(JailedPath::resolve('sub\\..\\secret', $this->root));
        self::assertNull(JailedPath::resolve('a\\b.jpg', $this->root));
    }

    public function testNulAndControlBytesAreRejected(): void
    {
        self::assertNull(JailedPath::resolve("a\0b.jpg", $this->root));
        self::assertNull(JailedPath::resolve('a%00b.jpg', $this->root));
        self::assertNull(JailedPath::resolve("a\x01b.jpg", $this->root));
        self::assertNull(JailedPath::resolve('a%01b.jpg', $this->root));
    }

    public function testDotSegmentsAreRejected(): void
    {
        self::assertNull(JailedPath::resolve('../secret', $this->root));
        self::assertNull(JailedPath::resolve('sub/../../secret', $this->root));
        self::assertNull(JailedPath::resolve('./secret', $this->root));
    }

    public function testSymlinkEscapeIsBlockedByRealpathJail(): void
    {
        file_put_contents($this->root . '/sub/real.jpg', 'INSIDE');
        @symlink($this->outsideFile, $this->root . '/sub/link.jpg');

        self::assertNull(JailedPath::resolve('sub/link.jpg', $this->root));
    }

    public function testEmptyAndMissingAndDirectoryReturnNull(): void
    {
        self::assertNull(JailedPath::resolve('', $this->root));
        self::assertNull(JailedPath::resolve('sub/missing.jpg', $this->root));
        self::assertNull(JailedPath::resolve('sub', $this->root));
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
