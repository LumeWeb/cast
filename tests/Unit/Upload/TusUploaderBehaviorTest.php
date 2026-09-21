<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Upload;

use Carbon\Carbon;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Publish\UploadIdentifier;
use LumeWeb\Cast\Publish\UploadRoute;
use LumeWeb\Cast\Publish\UploadSpec;
use LumeWeb\Cast\Upload\TusUploader;
use LumeWeb\Cast\Upload\TusUploadException;
use LumeWeb\Cast\Upload\UploadFileException;
use LumeWeb\Cast\Upload\UploadFileProblem;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TusPhp\Cache\Cacheable;
use TusPhp\Cache\FileStore;
use TusPhp\Tus\Client;

/**
 * Focused adapter behaviour the red contract test does not cover: exact
 * bounded chunk boundaries (never upload(-1)), HEAD-offset resume, HEAD offset
 * queries, DELETE cancel, typed artifact-file errors, typed server/library
 * failures, and bearer never leaking into any raised message.
 */
final class TusUploaderBehaviorTest extends TestCase
{
    private const TOKEN = 's3cret-bearer-token-9f8d';
    private const BASE_URL = 'https://portal.test';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cast-tus-behavior-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->dir, 0777, true));
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            $this->removeDir($this->dir);
        }
    }

    public function testUploadsInBoundedChunksWithExactOffsetsBodiesAndAuth(): void
    {
        $contents = 'abcdefghi';
        $path = $this->writeArtifact($contents);
        $requests = [];
        $stack = $this->stack([
            // creation-with-upload: first bounded chunk only
            new Response(201, ['Location' => $this->baseUrl() . '/uploads/abc123', 'Upload-Offset' => '4'], ''),
            // upload(4) -> HEAD then PATCH
            new Response(200, ['Upload-Offset' => '4'], ''),
            new Response(200, ['Upload-Offset' => '8'], ''),
            // upload(1) -> HEAD then PATCH
            new Response(200, ['Upload-Offset' => '8'], ''),
            new Response(200, ['Upload-Offset' => '9'], ''),
        ], $requests);

        $session = $this->uploader($stack, chunkBytes: 4)->uploadSession($this->spec($path, 'site.bin', 9));

        self::assertTrue($session->completed);
        self::assertSame(9, $session->offsetBytes);
        self::assertSame(9, $session->sizeBytes);
        self::assertSame($this->baseUrl() . '/uploads/abc123', $session->uploadUrl);

        self::assertCount(5, $requests);

        // POST carries the first bounded chunk (4 bytes), never the whole file.
        $create = $requests[0];
        self::assertSame('POST', $create->getMethod());
        self::assertSame($this->baseUrl() . '/api/upload/tus', (string) $create->getUri());
        self::assertSame('9', $create->getHeaderLine('Upload-Length'));
        self::assertSame('Bearer ' . self::TOKEN, $create->getHeaderLine('Authorization'));
        self::assertSame('4', $create->getHeaderLine('Content-Length'));
        self::assertSame('abcd', (string) $create->getBody());

        // Each upload() follows the HEAD-resume pattern and PATCHes exactly the
        // next bounded chunk at the reported offset (no single 9-byte PATCH).
        self::assertSame('HEAD', $requests[1]->getMethod());
        self::assertSame('PATCH', $requests[2]->getMethod());
        self::assertSame('https://portal.test/uploads/abc123', (string) $requests[1]->getUri());
        self::assertSame('4', $requests[2]->getHeaderLine('Upload-Offset'));
        self::assertSame('4', $requests[2]->getHeaderLine('Content-Length'));
        self::assertSame('efgh', (string) $requests[2]->getBody());
        self::assertSame('Bearer ' . self::TOKEN, $requests[2]->getHeaderLine('Authorization'));
        self::assertSame('application/offset+octet-stream', $requests[2]->getHeaderLine('Content-Type'));

        self::assertSame('HEAD', $requests[3]->getMethod());
        self::assertSame('PATCH', $requests[4]->getMethod());
        self::assertSame('8', $requests[4]->getHeaderLine('Upload-Offset'));
        self::assertSame('1', $requests[4]->getHeaderLine('Content-Length'));
        self::assertSame('i', (string) $requests[4]->getBody());
    }

    public function testResumesFromServerOffsetWithoutRecreating(): void
    {
        $contents = 'abcdefghi';
        $path = $this->writeArtifact($contents);
        $key = 'resume-key-abc';
        $cache = $this->cache();
        $this->seedLocation($cache, $key, $this->baseUrl() . '/uploads/resume1');

        $requests = [];
        $stack = $this->stack([
            new Response(200, ['Upload-Offset' => '6'], ''),
            new Response(200, ['Upload-Offset' => '6'], ''),
            new Response(200, ['Upload-Offset' => '9'], ''),
        ], $requests);

        $session = $this->uploader($stack, $cache)->uploadSession($this->spec($path, 'site.bin', 9), new UploadIdentifier($key));

        self::assertTrue($session->completed);
        self::assertSame(9, $session->offsetBytes);
        self::assertSame($this->baseUrl() . '/uploads/resume1', $session->uploadUrl);
        self::assertSame($key, $session->identifier()->value);

        // No POST create: a HEAD resume probe, then upload()'s own HEAD and the
        // final PATCH carrying only the remaining bytes.
        self::assertCount(3, $requests);
        self::assertSame('HEAD', $requests[0]->getMethod());
        self::assertSame('HEAD', $requests[1]->getMethod());
        self::assertSame('PATCH', $requests[2]->getMethod());
        self::assertSame('6', $requests[2]->getHeaderLine('Upload-Offset'));
        self::assertSame('3', $requests[2]->getHeaderLine('Content-Length'));
        self::assertSame('ghi', (string) $requests[2]->getBody());
        self::assertSame('Bearer ' . self::TOKEN, $requests[0]->getHeaderLine('Authorization'));
        self::assertSame('Bearer ' . self::TOKEN, $requests[1]->getHeaderLine('Authorization'));
        self::assertSame('Bearer ' . self::TOKEN, $requests[2]->getHeaderLine('Authorization'));
    }

    public function testResumeFallsBackToCreateWhenServerLostTheUpload(): void
    {
        $contents = 'abcdefghi';
        $path = $this->writeArtifact($contents);
        $key = 'resurrect-key';
        $cache = $this->cache();
        $this->seedLocation($cache, $key, $this->baseUrl() . '/uploads/gone1');

        $requests = [];
        $stack = $this->stack([
            new Response(404, [], ''), // HEAD: the server no longer tracks it
            new Response(201, ['Location' => $this->baseUrl() . '/uploads/gone2', 'Upload-Offset' => '9'], ''),
        ], $requests);

        $session = $this->uploader($stack, $cache)->uploadSession($this->spec($path, 'site.bin', 9), new UploadIdentifier($key));

        self::assertTrue($session->completed);
        self::assertSame(9, $session->offsetBytes);
        self::assertSame($this->baseUrl() . '/uploads/gone2', $session->uploadUrl);
        self::assertSame($key, $session->identifier()->value);

        self::assertCount(2, $requests);
        self::assertSame('HEAD', $requests[0]->getMethod());
        self::assertSame('POST', $requests[1]->getMethod());
        self::assertSame($key, $requests[1]->getHeaderLine('Upload-Key'));
        self::assertSame('Bearer ' . self::TOKEN, $requests[1]->getHeaderLine('Authorization'));
    }

    public function testQueriesServerOffsetForKnownUpload(): void
    {
        $cache = $this->cache();
        $this->seedLocation($cache, 'head-key-1', $this->baseUrl() . '/uploads/head1');

        $requests = [];
        $stack = $this->stack([
            new Response(200, ['Upload-Offset' => '6'], ''),
        ], $requests);

        $offset = $this->uploader($stack, $cache)->offset(new UploadIdentifier('head-key-1'));

        self::assertSame(6, $offset);
        self::assertCount(1, $requests);
        self::assertSame('HEAD', $requests[0]->getMethod());
        self::assertSame($this->baseUrl() . '/uploads/head1', (string) $requests[0]->getUri());
        self::assertSame('Bearer ' . self::TOKEN, $requests[0]->getHeaderLine('Authorization'));
        self::assertSame('1.0.0', $requests[0]->getHeaderLine('Tus-Resumable'));
    }

    public function testOffsetReturnsNullWhenServerDoesNotKnowUpload(): void
    {
        $requests = [];
        $stack = $this->stack([], $requests);

        $offset = $this->uploader($stack)->offset(new UploadIdentifier('never-created'));

        self::assertNull($offset);
        self::assertCount(0, $requests);
    }

    public function testCancelsActiveUploadWithBearer(): void
    {
        $key = 'cancel-key-xyz';
        $cache = $this->cache();
        $this->seedLocation($cache, $key, $this->baseUrl() . '/uploads/cancel1');

        $requests = [];
        $stack = $this->stack([
            new Response(204, [], ''),
        ], $requests);

        $this->uploader($stack, $cache)->cancel(new UploadIdentifier($key));

        self::assertCount(1, $requests);
        $request = $requests[0];
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame($this->baseUrl() . '/uploads/cancel1', (string) $request->getUri());
        self::assertSame('Bearer ' . self::TOKEN, $request->getHeaderLine('Authorization'));
        self::assertSame('1.0.0', $request->getHeaderLine('Tus-Resumable'));
    }

    public function testCancelOfUnknownUploadIsTypedAndNeverLeaksBearer(): void
    {
        $requests = [];
        $stack = $this->stack([], $requests);

        try {
            $this->uploader($stack)->cancel(new UploadIdentifier('never-created'));
            self::fail('Expected TusUploadException');
        } catch (TusUploadException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }

        self::assertCount(0, $requests);
    }

    public function testMissingFileIsTypedWithoutRequests(): void
    {
        $missing = $this->dir . '/does-not-exist.zip';
        $requests = [];
        $stack = $this->stack([], $requests);

        try {
            $this->uploader($stack)->uploadSession($this->spec($missing, 'missing.zip', 4));
            self::fail('Expected UploadFileException');
        } catch (UploadFileException $e) {
            self::assertSame(UploadFileProblem::NotFound, $e->problem());
            self::assertSame($missing, $e->path());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }

        self::assertCount(0, $requests);
    }

    public function testEmptyFileIsTypedWithoutRequests(): void
    {
        $path = $this->writeArtifact('');
        $requests = [];
        $stack = $this->stack([], $requests);

        try {
            $this->uploader($stack)->uploadSession($this->spec($path, 'empty.zip', 0));
            self::fail('Expected UploadFileException');
        } catch (UploadFileException $e) {
            self::assertSame(UploadFileProblem::Empty, $e->problem());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }

        self::assertCount(0, $requests);
    }

    public function testUnreadableFileIsTypedWithoutRequests(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('A chmod()-gated unreadable file cannot be simulated when running as root.');
        }

        $path = $this->writeArtifact('secret');
        chmod($path, 0000);
        try {
            $requests = [];
            $stack = $this->stack([], $requests);

            try {
                $this->uploader($stack)->uploadSession($this->spec($path, 'secret.zip', 6));
                self::fail('Expected UploadFileException');
            } catch (UploadFileException $e) {
                self::assertSame(UploadFileProblem::Unreadable, $e->problem());
                self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            }

            self::assertCount(0, $requests);
        } finally {
            chmod($path, 0o644);
        }
    }

    public function testRejectedCreateIsTypedAndNeverLeaksBearer(): void
    {
        $path = $this->writeArtifact('secret');
        $requests = [];
        $stack = $this->stack([
            new Response(401, ['Content-Type' => 'application/json'], '{"error":"unauthorized"}'),
        ], $requests);

        try {
            $this->uploader($stack)->uploadSession($this->spec($path, 'secret.zip', 6));
            self::fail('Expected TusUploadException');
        } catch (TusUploadException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, (string) $e->getPrevious()?->getMessage());
        }

        self::assertCount(1, $requests);
        self::assertSame('Bearer ' . self::TOKEN, $requests[0]->getHeaderLine('Authorization'));
    }

    public function testServerFailureOnCreateIsTyped(): void
    {
        $path = $this->writeArtifact('secret');
        $requests = [];
        $stack = $this->stack([
            new Response(500, ['Content-Type' => 'application/json'], '{"error":"boom"}'),
        ], $requests);

        try {
            $this->uploader($stack)->uploadSession($this->spec($path, 'secret.zip', 6));
            self::fail('Expected TusUploadException');
        } catch (TusUploadException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }

        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]->getMethod());
    }

    public function testRejectedChunkPatchIsTypedAndNeverLeaksBearer(): void
    {
        $contents = 'abcdefghij';
        $path = $this->writeArtifact($contents);
        $requests = [];
        $stack = $this->stack([
            new Response(201, ['Location' => $this->baseUrl() . '/uploads/abc123', 'Upload-Offset' => '5'], ''),
            new Response(200, ['Upload-Offset' => '5'], ''),
            // Server rejects the second PATCH.
            new Response(409, ['Content-Type' => 'application/json'], '{"error":"offset mismatch"}'),
        ], $requests);

        try {
            $this->uploader($stack, chunkBytes: 5)->uploadSession($this->spec($path, 'site.bin', 10));
            self::fail('Expected TusUploadException');
        } catch (TusUploadException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, (string) $e->getPrevious()?->getMessage());
        }

        self::assertCount(3, $requests);
        self::assertSame('PATCH', $requests[2]->getMethod());
        self::assertSame('Bearer ' . self::TOKEN, $requests[2]->getHeaderLine('Authorization'));
    }

    public function testTransportFailureOnCreateIsTypedAndNeverLeaksBearer(): void
    {
        $path = $this->writeArtifact('secret');
        $requests = [];
        $connect = new ConnectException(
            'Connection refused',
            new Request('POST', $this->baseUrl() . '/api/upload/tus'),
        );
        $stack = $this->stack([$connect], $requests);

        try {
            $this->uploader($stack)->uploadSession($this->spec($path, 'secret.zip', 6));
            self::fail('Expected TusUploadException');
        } catch (TusUploadException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, (string) $e->getPrevious()?->getMessage());
        }

        self::assertCount(1, $requests);
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
     * @param list<Response|\GuzzleHttp\Exception\ConnectException> $queue
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

    private function uploader(HandlerStack $stack, ?Cacheable $cache = null, ?int $chunkBytes = null): TusUploader
    {
        return new TusUploader(
            baseUrl: self::BASE_URL,
            bearerToken: self::TOKEN,
            cache: $cache ?? $this->cache(),
            clientFactory: fn (string $baseUrl, array $options): Client => new Client($baseUrl, ['handler' => $stack] + $options),
            chunkBytes: $chunkBytes ?? TusUploader::DEFAULT_CHUNK_BYTES,
        );
    }

    private function cache(): FileStore
    {
        return new FileStore($this->dir . '/', 'client.cache');
    }

    /**
     * Record a server upload location in the same tus-php FileStore format the
     * adapter's client writes (prefix "tus:client:", RFC-7231 expiry).
     */
    private function seedLocation(Cacheable $cache, string $key, string $location): void
    {
        $cache->setPrefix('tus:client:');
        $cache->set($key, [
            'location' => $location,
            'expires_at' => Carbon::now()->addHour()->format(Cacheable::RFC_7231),
        ]);
    }

    private function baseUrl(): string
    {
        return self::BASE_URL;
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
