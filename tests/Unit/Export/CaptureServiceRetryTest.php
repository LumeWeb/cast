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
use PHPUnit\Framework\TestCase;

final class CaptureServiceRetryTest extends TestCase
{
    private string $workDir;
    private string $jailRoot;
    private LocalOutputFileSystem $files;
    private Origin $origin;
    private WorkItemFactory $factory;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/cast-retry-' . bin2hex(random_bytes(6));
        $this->jailRoot = sys_get_temp_dir() . '/cast-retry-jail-' . bin2hex(random_bytes(6));
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

    public function testTransportErrorThenSuccessFetches(): void
    {
        $transport = new FakeCaptureTransport([
            FakeCaptureTransport::ERROR,
            CaptureResponse::withString(200, [], $this->html()),
        ]);

        $result = $this->capture('https://example.com/', $transport);

        self::assertSame(CaptureOutcome::Fetched, $result->outcome);
        self::assertSame(2, $result->attempts);
        self::assertSame(0, $result->retryDelaySeconds);
        self::assertCount(2, $transport->requested);
    }

    public function testPersistent500FailsAfterThreeAttempts(): void
    {
        $transport = new FakeCaptureTransport([
            CaptureResponse::empty(500),
            CaptureResponse::empty(500),
            CaptureResponse::empty(500),
        ]);

        $result = $this->capture('https://example.com/', $transport);

        self::assertSame(CaptureOutcome::Failed, $result->outcome);
        self::assertSame(3, $result->attempts);
        self::assertSame(20, $result->retryDelaySeconds);
    }

    public function test500Then200Fetches(): void
    {
        $transport = new FakeCaptureTransport([
            CaptureResponse::empty(500),
            CaptureResponse::withString(200, [], $this->html()),
        ]);

        $result = $this->capture('https://example.com/', $transport);

        self::assertSame(CaptureOutcome::Fetched, $result->outcome);
        self::assertSame(2, $result->attempts);
    }

    public function testEmptyBodyRetriedThenFetched(): void
    {
        $transport = new FakeCaptureTransport([
            CaptureResponse::empty(200),
            CaptureResponse::withString(200, [], $this->html()),
        ]);

        $result = $this->capture('https://example.com/', $transport);

        self::assertSame(CaptureOutcome::Fetched, $result->outcome);
        self::assertSame(2, $result->attempts);
        self::assertCount(2, $transport->requested);
    }

    public function testEmptyBodyExhaustedFailsWithRetryDelay(): void
    {
        $transport = new FakeCaptureTransport([
            CaptureResponse::empty(200),
            CaptureResponse::empty(200),
            CaptureResponse::empty(200),
        ]);

        $result = $this->capture('https://example.com/', $transport);

        self::assertSame(CaptureOutcome::Empty, $result->outcome);
        self::assertSame(3, $result->attempts);
        self::assertSame(20, $result->retryDelaySeconds);
        self::assertCount(3, $transport->requested);
    }

    public function testPermanentOutcomeIsNotRetried(): void
    {
        $transport = new FakeCaptureTransport([CaptureResponse::empty(404)]);

        $result = $this->capture('https://example.com/', $transport);

        self::assertSame(CaptureOutcome::NotFound, $result->outcome);
        self::assertSame(1, $result->attempts);
        self::assertSame(0, $result->retryDelaySeconds);
        self::assertCount(1, $transport->requested);
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

    private function html(): string
    {
        return '<html><body>' . str_repeat('<p>content</p>', 120) . '</body></html>';
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
