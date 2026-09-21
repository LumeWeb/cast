<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\CaptureOutcome;
use LumeWeb\Cast\Export\CaptureResponse;
use LumeWeb\Cast\Export\CaptureService;
use LumeWeb\Cast\Export\LocalOutputFileSystem;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\WordPressAssetFileSystem;
use LumeWeb\Cast\Export\WordPressAssetRoots;
use LumeWeb\Cast\Export\WorkItemFactory;
use PHPUnit\Framework\TestCase;

/**
 * Wires the WordPress asset file system into the real CaptureService to prove
 * the injected boundary drives the local-vs-HTTP fallback: an asset on disk is
 * copied without any transport call, a missing one falls through to wp_remote_get.
 */
final class WordPressAssetFileSystemCaptureTest extends TestCase
{
    private string $root;
    private string $uploadsDir;
    private string $workDir;
    private LocalOutputFileSystem $files;
    private WordPressAssetFileSystem $disk;
    private Origin $origin;
    private WorkItemFactory $factory;
    private UrlCanonicalizer $canonicalizer;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cast-wcap-' . bin2hex(random_bytes(6));
        $this->uploadsDir = $this->root . '/wp-content/uploads';
        $this->workDir = sys_get_temp_dir() . '/cast-wcap-out-' . bin2hex(random_bytes(6));
        mkdir($this->uploadsDir . '/2024/01', 0777, true);
        mkdir($this->workDir, 0777, true);

        $this->files = new LocalOutputFileSystem($this->workDir);
        $this->disk = new WordPressAssetFileSystem(new WordPressAssetRoots(
            $this->root . '/wp-content',
            $this->uploadsDir,
            $this->root . '/wp-content/plugins',
            $this->root . '/wp-content/themes',
            $this->root,
        ));
        $this->canonicalizer = new UrlCanonicalizer();
        $this->origin = Origin::fromUrl($this->canonicalizer->canonicalize('https://example.com/'));
        $this->factory = new WorkItemFactory();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        $this->removeTree($this->workDir);
    }

    public function testLocalAssetIsCopiedWithoutAnyHttpRequest(): void
    {
        file_put_contents($this->uploadsDir . '/2024/01/photo.jpg', 'JPG-DATA');
        $transport = new FakeCaptureTransport([]);

        $result = $this->capture('https://example.com/wp-content/uploads/2024/01/photo.jpg', $transport);

        self::assertSame(CaptureOutcome::Copied, $result->outcome);
        self::assertSame([], $transport->requested);
        self::assertSame('JPG-DATA', file_get_contents($this->workDir . '/wp-content/uploads/2024/01/photo.jpg'));
    }

    public function testMissingLocalAssetFallsBackToHttp(): void
    {
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], 'BINARY-DATA'),
        ]);

        $result = $this->capture('https://example.com/wp-content/uploads/nope.jpg', $transport);

        self::assertSame(CaptureOutcome::Fetched, $result->outcome);
        self::assertSame(['https://example.com/wp-content/uploads/nope.jpg'], $transport->requested);
        self::assertSame('BINARY-DATA', file_get_contents($this->workDir . '/wp-content/uploads/nope.jpg'));
    }

    private function capture(string $url, FakeCaptureTransport $transport): \LumeWeb\Cast\Export\CaptureResult
    {
        $service = new CaptureService($transport, $this->origin, $this->files, $this->disk);

        return $service->capture($this->factory->fromString($url));
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
