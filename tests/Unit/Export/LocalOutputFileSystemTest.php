<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\LocalOutputFileSystem;
use PHPUnit\Framework\TestCase;

final class LocalOutputFileSystemTest extends TestCase
{
    private string $workDir;
    private LocalOutputFileSystem $files;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/cast-out-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0777, true);
        $this->files = new LocalOutputFileSystem($this->workDir);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workDir);
    }

    public function testPutCreatesNestedFile(): void
    {
        $this->files->put('wp-content/uploads/2024/01/photo.jpg', 'JPG-DATA');

        self::assertTrue($this->files->exists('wp-content/uploads/2024/01/photo.jpg'));
        self::assertSame(
            'JPG-DATA',
            file_get_contents($this->workDir . '/wp-content/uploads/2024/01/photo.jpg')
        );
    }

    public function testPutOverwritesExistingContent(): void
    {
        $this->files->put('index.html', 'old');
        $this->files->put('index.html', 'new');

        self::assertSame('new', $this->files->exists('index.html')
            ? file_get_contents($this->workDir . '/index.html')
            : null);
    }

    public function testDeleteRemovesFile(): void
    {
        $this->files->put('sub/page/index.html', 'x');

        $this->files->delete('sub/page/index.html');

        self::assertFalse($this->files->exists('sub/page/index.html'));
    }

    public function testDeleteMissingFileIsNoOp(): void
    {
        $this->files->delete('never/created.html');

        self::assertFalse($this->files->exists('never/created.html'));
    }

    public function testTraversalPathIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->files->put('../escape.html', 'x');
    }

    public function testPutRequiresExistingWorkDirectory(): void
    {
        $files = new LocalOutputFileSystem($this->workDir . '/missing');

        $this->expectException(\RuntimeException::class);

        $files->put('index.html', 'x');
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
