<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\CaptureOutcome;
use LumeWeb\Cast\Export\CaptureRequest;
use LumeWeb\Cast\Export\CaptureResponse;
use LumeWeb\Cast\Export\CaptureResult;
use LumeWeb\Cast\Export\CaptureService;
use LumeWeb\Cast\Export\JailedDiskAssetSource;
use LumeWeb\Cast\Export\LocalOutputFileSystem;
use LumeWeb\Cast\Export\OffOriginUrl;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemFactory;
use PHPUnit\Framework\TestCase;

final class CaptureServiceTest extends TestCase
{
    private string $workDir;
    private string $jailRoot;
    private LocalOutputFileSystem $files;
    private JailedDiskAssetSource $disk;
    private Origin $origin;
    private WorkItemFactory $factory;
    private UrlCanonicalizer $canonicalizer;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/cast-work-' . bin2hex(random_bytes(6));
        $this->jailRoot = sys_get_temp_dir() . '/cast-jail-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0777, true);
        mkdir($this->jailRoot, 0777, true);

        $this->files = new LocalOutputFileSystem($this->workDir);
        $this->disk = new JailedDiskAssetSource($this->jailRoot);
        $this->canonicalizer = new UrlCanonicalizer();
        $this->origin = Origin::fromUrl($this->canonicalizer->canonicalize('https://example.com/'));
        $this->factory = new WorkItemFactory();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workDir);
        $this->removeTree($this->jailRoot);
    }

    public function testLocalAssetIsCopiedWithoutAnyHttpRequest(): void
    {
        $this->writeJailFile('wp-content/themes/x/style.css', 'BODY { color: red; }');
        $transport = new FakeCaptureTransport([]);

        $result = $this->capture('https://example.com/wp-content/themes/x/style.css', $transport);

        self::assertSame(CaptureOutcome::Copied, $result->outcome);
        self::assertSame('wp-content/themes/x/style.css', $result->outputPath);
        self::assertSame(md5('BODY { color: red; }'), $result->contentHash);
        self::assertSame([], $transport->requested);
        self::assertSame(
            'BODY { color: red; }',
            file_get_contents($this->workDir . '/wp-content/themes/x/style.css')
        );
    }

    public function testMissingAssetFallsBackToHttp(): void
    {
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], 'BINARY-DATA'),
        ]);

        $result = $this->capture('https://example.com/wp-content/uploads/nope.jpg', $transport);

        self::assertSame(CaptureOutcome::Fetched, $result->outcome);
        self::assertSame(['https://example.com/wp-content/uploads/nope.jpg'], $transport->requested);
        self::assertSame('BINARY-DATA', file_get_contents($this->workDir . '/wp-content/uploads/nope.jpg'));
    }

    public function testPrettyPageWritesIndexHtml(): void
    {
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], $this->html('about page')),
        ]);

        $result = $this->capture('https://example.com/about', $transport);

        self::assertSame(CaptureOutcome::Fetched, $result->outcome);
        self::assertSame('about/index.html', $result->outputPath);
        self::assertTrue($this->files->exists('about/index.html'));
    }

    public function testFixedTextFileRetainsItsName(): void
    {
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], 'User-agent: *'),
        ]);

        $result = $this->capture('https://example.com/robots.txt', $transport);

        self::assertSame(CaptureOutcome::Fetched, $result->outcome);
        self::assertSame('robots.txt', $result->outputPath);
        self::assertSame('User-agent: *', file_get_contents($this->workDir . '/robots.txt'));
    }

    public function testQueryPageWritesHashedSubdirectory(): void
    {
        $hash = substr(md5('/?s=hello'), 0, 12);
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], $this->html('search')),
        ]);

        $result = $this->capture('https://example.com/?s=hello', $transport);

        self::assertSame("__qs/{$hash}/index.html", $result->outputPath);
        self::assertTrue($this->files->exists("__qs/{$hash}/index.html"));
    }

    public function testAcceptedBodyExposesContentHash(): void
    {
        $body = $this->html('hash me');
        $transport = new FakeCaptureTransport([CaptureResponse::withString(200, [], $body)]);

        $result = $this->capture('https://example.com/', $transport);

        self::assertSame(md5($body), $result->contentHash);
        self::assertSame($body, file_get_contents($this->workDir . '/index.html'));
    }

    public function testEmpty200BodyFails(): void
    {
        $transport = new FakeCaptureTransport([CaptureResponse::empty(200)]);

        $result = $this->capture('https://example.com/', $transport);

        self::assertSame(CaptureOutcome::Empty, $result->outcome);
        self::assertFalse($this->files->exists('index.html'));
    }

    public function testGhostGuardRejectsTinyHtml(): void
    {
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], '<html><body>tiny</body></html>'),
        ]);

        $result = $this->capture('https://example.com/tiny', $transport);

        self::assertSame(CaptureOutcome::Ghost, $result->outcome);
        self::assertFalse($this->files->exists('tiny/index.html'));
    }

    public function testGhostGuardRejectsHtmlWithoutHtmlTag(): void
    {
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(200, [], str_repeat('x', 2000)),
        ]);

        $result = $this->capture('https://example.com/no-tag', $transport);

        self::assertSame(CaptureOutcome::Ghost, $result->outcome);
    }

    public function test403BodyIsNeverSaved(): void
    {
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(403, [], 'forbidden body'),
        ]);

        $result = $this->capture('https://example.com/secret', $transport);

        self::assertSame(CaptureOutcome::Forbidden, $result->outcome);
        self::assertFalse($this->files->exists('secret/index.html'));
    }

    public function testRandom404FailsAndDeletesStaleOutput(): void
    {
        $this->files->put('gone/index.html', 'stale');
        $transport = new FakeCaptureTransport([CaptureResponse::empty(404)]);

        $result = $this->capture('https://example.com/gone', $transport);

        self::assertSame(CaptureOutcome::NotFound, $result->outcome);
        self::assertFalse($this->files->exists('gone/index.html'));
    }

    public function testDedicated404IsWrittenTo404Html(): void
    {
        $transport = new FakeCaptureTransport([
            CaptureResponse::withString(404, [], 'not found page'),
        ]);

        $result = $this->capture('https://example.com/404', $transport);

        self::assertSame(CaptureOutcome::Fetched, $result->outcome);
        self::assertSame('404.html', $result->outputPath);
        self::assertSame('not found page', file_get_contents($this->workDir . '/404.html'));
    }

    public function testOffOriginItemIsDeniedBeforeAnyFetch(): void
    {
        $transport = new FakeCaptureTransport([]);

        try {
            $this->capture('https://evil.example.com/x', $transport);
            self::fail('Expected OffOriginUrl to be thrown');
        } catch (OffOriginUrl $exception) {
            self::assertStringContainsString('evil.example.com', $exception->getMessage());
        }

        self::assertSame([], $transport->requested);
    }

    private function capture(string $raw, FakeCaptureTransport $transport): CaptureResult
    {
        $service = new CaptureService(
            $transport,
            $this->origin,
            $this->files,
            $this->disk,
        );

        return $service->capture($this->item($raw));
    }

    private function item(string $raw): WorkItem
    {
        return $this->factory->fromString($raw);
    }

    private function html(string $label): string
    {
        return '<html><head><title>' . $label . '</title></head><body>' . str_repeat('<p>content</p>', 120) . '</body></html>';
    }

    private function writeJailFile(string $relative, string $contents): void
    {
        $path = $this->jailRoot . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
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
