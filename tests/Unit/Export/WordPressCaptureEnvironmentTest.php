<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\CaptureOutcome;
use LumeWeb\Cast\Export\CaptureService;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\WordPressCaptureEnvironment;
use LumeWeb\Cast\Export\WorkItemFactory;
use PHPUnit\Framework\TestCase;

/**
 * The WordPress {@see CaptureEnvironment} adapter: builds a real per-run
 * {@see CaptureService} over the live WordPress HTTP stack
 * ({@see \LumeWeb\Cast\Export\WordPressCaptureTransport} +
 * {@see \LumeWeb\Cast\Export\WordPressCaptureHttp}), the jailed local asset
 * source ({@see \LumeWeb\Cast\Export\WordPressAssetFileSystem} over live
 * uploads roots), and an atomic {@see \LumeWeb\Cast\Export\LocalOutputFileSystem}
 * on the run's work directory — capturing strictly anonymously, matching the
 * probe's no-credential contract (the deployment's Caddy bypasses Basic Auth
 * on the plugin's own loopback requests).
 */
final class WordPressCaptureEnvironmentTest extends TestCase
{
    private WorkItemFactory $factory;

    private string $workDir;

    protected function setUp(): void
    {
        $this->factory = new WorkItemFactory();
        $this->workDir = sys_get_temp_dir() . '/cast-cap-env-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0777, true);

        $GLOBALS['lumeweb_cast_home_url'] = 'https://blog.example.test/';
        $GLOBALS['lumeweb_cast_env_uploads'] = sys_get_temp_dir();
        $GLOBALS['lumeweb_cast_upload_dir'] = [
            'basedir' => sys_get_temp_dir(),
            'error' => false,
        ];
        $GLOBALS['lumeweb_cast_environment_type'] = 'production';
        $GLOBALS['lumeweb_cast_wp_remote_calls'] = [];
        $GLOBALS['lumeweb_cast_wp_remote_response'] = [
            'headers' => ['content-type' => 'text/html; charset=UTF-8'],
            'body' => $this->htmlBody(),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
        $GLOBALS['lumeweb_cast_wp_remote_responses'] = [];
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workDir);
    }

    public function testCaptureServiceWiresTheWordPressRuntimeForTheGivenWorkDirectory(): void
    {
        $service = $this->environment()->captureService(
            Origin::fromParts('https', 'blog.example.test', null),
            $this->workDir,
        );

        self::assertInstanceOf(CaptureService::class, $service);
    }

    public function testCaptureServiceFetchesAnonymouslyIgnoringAdminAuthContext(): void
    {
        // Even when the incoming admin request carries HTTP Basic Auth, the
        // capture service fetches the export origin anonymously — probe and
        // capture share the same no-credential contract (the deployment's
        // Caddy bypasses Basic Auth on the plugin's own loopback requests).
        $_SERVER['PHP_AUTH_USER'] = 'alice';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('alice:s3cret');
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('alice:s3cret');

        $origin = Origin::fromParts('https', 'blog.example.test', null);
        $service = $this->environment()->captureService($origin, $this->workDir);
        $item = $this->factory->fromString('https://blog.example.test/');

        $result = $service->capture($item);

        // The page travelled the real WordPress HTTP stack (no local file for
        // a top-level page), landed on the atomic local output filesystem...
        self::assertSame(CaptureOutcome::Fetched, $result->outcome);
        self::assertFileExists($this->workDir . DIRECTORY_SEPARATOR . $item->outputPath());
        self::assertSame('https://blog.example.test/', $GLOBALS['lumeweb_cast_wp_remote_calls'][0]['url']);

        // ...and capture never sends Basic Auth: no Authorization header was
        // ever attached to the request, regardless of the admin auth context.
        $headers = $GLOBALS['lumeweb_cast_wp_remote_calls'][0]['args']['headers'] ?? [];
        self::assertIsArray($headers);
        self::assertArrayNotHasKey('Authorization', $headers);
    }

    public function testCaptureServiceServesAssetsFromTheLiveWordPressUploadsRootWithoutHttp(): void
    {
        $uploads = sys_get_temp_dir() . '/cast-cap-uploads-' . bin2hex(random_bytes(6));
        $assetDir = $uploads . '/2025/01';
        mkdir($assetDir, 0777, true);
        $asset = $assetDir . '/logo.png';
        file_put_contents($asset, 'PNG-FIXTURE');

        $this->workDir = sys_get_temp_dir() . '/cast-cap-env-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0777, true);
        $GLOBALS['lumeweb_cast_upload_dir'] = ['basedir' => $uploads, 'error' => false];

        $origin = Origin::fromParts('https', 'blog.example.test', null);
        $service = $this->environment()->captureService($origin, $this->workDir);
        $item = $this->factory->fromString('https://blog.example.test/wp-content/uploads/2025/01/logo.png');

        $result = $service->capture($item);

        self::assertSame(CaptureOutcome::Copied, $result->outcome);
        self::assertSame('PNG-FIXTURE', file_get_contents($this->workDir . DIRECTORY_SEPARATOR . $item->outputPath()));
        self::assertSame([], $GLOBALS['lumeweb_cast_wp_remote_calls']);

        $this->removeTree($uploads);
    }

    private function environment(): WordPressCaptureEnvironment
    {
        return new WordPressCaptureEnvironment();
    }

    private function htmlBody(): string
    {
        return '<!DOCTYPE html><html><head><title>Home</title></head><body><p>' . str_repeat('welcome', 200) . '</p></body></html>';
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }
}
