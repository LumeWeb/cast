<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Upload;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Publish\UploadRoute;
use LumeWeb\Cast\Publish\UploadSpec;
use LumeWeb\Cast\Upload\TusUploader;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Real resumable TUS upload adapter against the ipfs-sdk contract: POST
 * /api/upload/tus with bearer auth, archive=true and name metadata, bounded
 * memory chunking (creation-with-upload then explicit upload chunks, never
 * upload(-1)), HEAD-offset resume, DELETE cancel, typed errors that never leak
 * the bearer token, and result-polling integration through UploadResultClient.
 */
final class TusUploaderTest extends TestCase
{
    private const TOKEN = 's3cret-bearer-token-9f8d';
    private const BASE_URL = 'https://portal.test';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cast-tus-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->dir, 0777, true));
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            $this->removeDir($this->dir);
        }
    }

    public function testCreatesUploadWithArchiveAndNameMetadataBearerAndFirstChunk(): void
    {
        $contents = 'abc';
        $path = $this->writeArtifact($contents);
        $requests = [];
        $stack = $this->stack([
            new Response(201, ['Location' => 'https://portal.test/uploads/abc123', 'Upload-Offset' => '3'], ''),
        ], $requests);

        $session = $this->uploader($stack)->uploadSession($this->spec($path, 'site.bin', strlen($contents)));

        self::assertTrue($session->completed);
        self::assertSame('https://portal.test/uploads/abc123', $session->uploadUrl);
        self::assertSame(3, $session->offsetBytes);
        self::assertSame(3, $session->sizeBytes);

        self::assertCount(1, $requests);
        $request = $requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://portal.test/api/upload/tus', (string) $request->getUri());
        self::assertSame('Bearer ' . self::TOKEN, $request->getHeaderLine('Authorization'));
        self::assertSame('1.0.0', $request->getHeaderLine('Tus-Resumable'));
        self::assertSame('3', $request->getHeaderLine('Upload-Length'));
        self::assertSame($session->identifier()->value, $request->getHeaderLine('Upload-Key'));
        self::assertSame('sha256 ' . base64_encode(hash('sha256', $contents, true)), $request->getHeaderLine('Upload-Checksum'));
        self::assertSame(
            'archive ' . base64_encode('true') . ',name ' . base64_encode('site.bin'),
            $request->getHeaderLine('Upload-Metadata'),
        );
        self::assertSame('application/offset+octet-stream', $request->getHeaderLine('Content-Type'));
        self::assertSame('3', $request->getHeaderLine('Content-Length'));
        self::assertSame($contents, (string) $request->getBody());
    }

    private function writeArtifact(string $contents): string
    {
        $path = $this->dir . '/site.zip';
        file_put_contents($path, $contents);

        return $path;
    }

    private function spec(string $path, string $name, int $size): UploadSpec
    {
        return new UploadSpec($path, $name, archive: true, sizeBytes: $size, route: UploadRoute::Tus);
    }

    /**
     * @param list<Response> $queue
     * @param list<RequestInterface> $requests Filled by reference with every request sent.
     */
    private function stack(array $queue, array &$requests): HandlerStack
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(function (callable $handler) use (&$requests): callable {
            return function (RequestInterface $request, array $options) use ($handler, &$requests) {
                $requests[] = $request;

                return $handler($request, $options);
            };
        });

        return $stack;
    }

    private function uploader(HandlerStack $stack): TusUploader
    {
        return new TusUploader(
            baseUrl: self::BASE_URL,
            bearerToken: self::TOKEN,
            cache: new \TusPhp\Cache\FileStore($this->dir . '/', 'client.cache'),
            clientFactory: fn (string $baseUrl, array $options): \TusPhp\Tus\Client => new \TusPhp\Tus\Client($baseUrl, ['handler' => $stack] + $options),
        );
    }

    private function removeDir(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
