<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\CaptureOutcome;
use LumeWeb\Cast\Export\CaptureResponse;
use LumeWeb\Cast\Export\CaptureResult;
use LumeWeb\Cast\Export\CaptureService;
use LumeWeb\Cast\Export\JailedDiskAssetSource;
use LumeWeb\Cast\Export\LocalOutputFileSystem;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CaptureServiceRedirectTest extends TestCase
{
    private string $workDir;
    private string $jailRoot;
    private LocalOutputFileSystem $files;
    private Origin $origin;
    private WorkItemFactory $factory;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/cast-redir-' . bin2hex(random_bytes(6));
        $this->jailRoot = sys_get_temp_dir() . '/cast-redir-jail-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0777, true);
        mkdir($this->jailRoot, 0777, true);

        $this->files = new LocalOutputFileSystem($this->workDir);
        $this->origin = Origin::fromUrl((new UrlCanonicalizer())->canonicalize('https://example.com/'));
        $this->factory = new WorkItemFactory();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workDir);
        $this->removeTree($this->jailRoot);
    }

    public function test301AbsoluteRedirectWritesMetaRefreshStub(): void
    {
        $transport = new FakeCaptureTransport([
            $this->redirect(301, 'https://example.com/new-page'),
        ]);

        $result = $this->capture('https://example.com/about', $transport);

        self::assertSame(CaptureOutcome::Redirected, $result->outcome);
        self::assertSame('https://example.com/new-page', $result->redirectTarget);
        self::assertSame('about/index.html', $result->outputPath);
        self::assertNotNull($result->contentHash);
        self::assertTrue($this->files->exists('about/index.html'));

        $stub = (string) file_get_contents($this->workDir . '/about/index.html');
        self::assertStringContainsString('http-equiv="refresh"', $stub);
        self::assertStringContainsString('url=https://example.com/new-page', $stub);
        self::assertStringContainsString('<link rel="canonical" href="https://example.com/new-page">', $stub);
        // The source is fetched once to learn the redirect; the target is never
        // followed here — it is queued by the crawler (mirrors the off-origin
        // sibling test, which asserts the identical single fetch).
        self::assertSame(['https://example.com/about'], $transport->requested);
    }

    public function testRelativeLocationResolvesToLocalRedirect(): void
    {
        $transport = new FakeCaptureTransport([
            $this->redirect(302, '../moved'),
        ]);

        $result = $this->capture('https://example.com/dir/page/', $transport);

        self::assertSame(CaptureOutcome::Redirected, $result->outcome);
        self::assertSame('https://example.com/dir/moved', $result->redirectTarget);
    }

    public function testCanonicalSlashTwinWritesNothing(): void
    {
        $transport = new FakeCaptureTransport([
            $this->redirect(301, 'https://example.com/about/'),
        ]);

        $result = $this->capture('https://example.com/about', $transport);

        self::assertSame(CaptureOutcome::CanonicalTwin, $result->outcome);
        self::assertSame('https://example.com/about/', $result->redirectTarget);
        self::assertFalse($this->files->exists('about/index.html'));
    }

    public function testSchemeTwinWritesNothing(): void
    {
        $transport = new FakeCaptureTransport([
            $this->redirect(301, 'http://example.com/about/'),
        ]);

        $result = $this->capture('https://example.com/about', $transport);

        self::assertSame(CaptureOutcome::CanonicalTwin, $result->outcome);
        self::assertFalse($this->files->exists('about/index.html'));
    }

    #[DataProvider('redirectStatuses')]
    public function testAllRedirectStatusesProduceARedirectResult(int $status): void
    {
        $transport = new FakeCaptureTransport([
            $this->redirect($status, 'https://example.com/new-page'),
        ]);

        $result = $this->capture('https://example.com/about', $transport);

        self::assertSame(CaptureOutcome::Redirected, $result->outcome);
        self::assertSame('https://example.com/new-page', $result->redirectTarget);
    }

    /**
     * Numeric-string keys are cast to integers by PHP, so the declared key
     * shape is int.
     *
     * @return array<int, array{int}>
     */
    public static function redirectStatuses(): array
    {
        return [
            '301' => [301],
            '302' => [302],
            '303' => [303],
            '307' => [307],
            '308' => [308],
        ];
    }

    public function testAssetRedirectQueuesTargetWithoutStub(): void
    {
        $transport = new FakeCaptureTransport([
            $this->redirect(301, 'https://example.com/wp-content/uploads/y.jpg'),
        ]);

        $result = $this->capture('https://example.com/wp-content/uploads/x.jpg', $transport);

        self::assertSame(CaptureOutcome::Redirected, $result->outcome);
        self::assertSame('https://example.com/wp-content/uploads/y.jpg', $result->redirectTarget);
        self::assertFalse($this->files->exists('wp-content/uploads/x.jpg'));
    }

    public function testOffOriginRedirectTargetIsRecordedNotFetched(): void
    {
        $transport = new FakeCaptureTransport([
            $this->redirect(301, 'https://evil.example.com/x'),
        ]);

        $result = $this->capture('https://example.com/about', $transport);

        self::assertSame(CaptureOutcome::OffOrigin, $result->outcome);
        self::assertSame('https://evil.example.com/x', $result->redirectTarget);
        self::assertFalse($this->files->exists('about/index.html'));
        self::assertSame(['https://example.com/about'], $transport->requested);
    }

    public function testRedirectWithoutLocationFailsAfterRetries(): void
    {
        $transport = new FakeCaptureTransport([
            CaptureResponse::empty(301),
            CaptureResponse::empty(301),
            CaptureResponse::empty(301),
        ]);

        $result = $this->capture('https://example.com/about', $transport);

        self::assertSame(CaptureOutcome::Failed, $result->outcome);
        self::assertSame(3, $result->attempts);
    }

    private function redirect(int $status, string $location): CaptureResponse
    {
        return CaptureResponse::empty($status, ['location' => $location]);
    }

    private function capture(string $raw, FakeCaptureTransport $transport): CaptureResult
    {
        $service = new CaptureService(
            $transport,
            $this->origin,
            $this->files,
            new JailedDiskAssetSource($this->jailRoot),
        );

        return $service->capture($this->item($raw));
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
