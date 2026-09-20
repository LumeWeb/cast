<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use Carbon\Carbon;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Http\TransportException;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use LumeWeb\Cast\Publish\IpfsUploadClient;
use LumeWeb\Cast\Publish\UploadClientException;
use LumeWeb\Cast\Publish\UploadIdentifier;
use LumeWeb\Cast\Publish\UploadResult;
use LumeWeb\Cast\Publish\UploadRoute;
use LumeWeb\Cast\Publish\UploadSpec;
use LumeWeb\Cast\Publish\UploadStatus;
use LumeWeb\Cast\Tests\Unit\Support\RecordingTransport;
use LumeWeb\Cast\Upload\PostUploader;
use LumeWeb\Cast\Upload\TusUploader;
use LumeWeb\Cast\Upload\TusUploadException;
use LumeWeb\Cast\Upload\UploadFileException;
use LumeWeb\Cast\Upload\UploadFileProblem;
use LumeWeb\Cast\Upload\UploadRedirectException;
use LumeWeb\Cast\Upload\UploadResultClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TusPhp\Cache\Cacheable;
use TusPhp\Cache\FileStore;
use TusPhp\Tus\Client;

/**
 * IpfsUploadClient bridges the real ipfs-sdk uploaders onto the Publish
 * UploadClient boundary. upload() picks the transport from the route the
 * UploadRouter already decided: the POST route retains its already-terminal
 * result and replays it on poll() (no result endpoint exists for it), while the
 * TUS route returns the session's Upload-Key as the identifier that poll()
 * delegates to the UploadResultClient. Every typed Upload/Http failure is
 * rethrown as a secret-safe UploadClientException carrying the original as
 * $previous — the bearer token only ever appears on the wire inside the wrapped
 * clients, never in an adapter message.
 */
final class IpfsUploadClientTest extends TestCase
{
    private const TOKEN = 's3cret-bearer-token-9f8d';
    private const BASE_URL = 'https://portal.test';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cast-upload-client-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->dir, 0777, true));
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            $this->removeDir($this->dir);
        }
    }

    public function testPostUploadReturnsIdentifierAndPollReplaysTerminalResult(): void
    {
        $path = $this->writeArtifact('hello world');
        $postRecording = RecordingTransport::withResponses([
            new Response(200, ['Content-Type' => 'application/json'], '{"CID":"QmFromPost"}'),
        ]);
        $resultRecording = RecordingTransport::withResponses([]);
        $postRequests = [];

        $adapter = $this->adapter(
            new PostUploader($postRecording->transport(), self::BASE_URL, self::TOKEN),
            $this->tus($this->stack([], $postRequests)),
            new UploadResultClient($resultRecording->transport(), self::BASE_URL, self::TOKEN),
        );

        $identifier = $adapter->upload($this->spec($path, UploadRoute::Post, 11));

        self::assertSame('post-1', $identifier->value);

        $result = $adapter->poll($identifier);

        self::assertInstanceOf(UploadResult::class, $result);
        self::assertTrue($result->isTerminal());
        self::assertTrue($result->isSuccess());
        self::assertSame(UploadStatus::Completed, $result->status);
        self::assertSame('QmFromPost', $result->cid);
        self::assertSame(11, $result->size);
        self::assertSame([], $resultRecording->requests(), 'Replaying a POST result must not hit the result endpoint.');

        $request = $postRecording->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/upload?archive=true&name=site.zip', (string) $request->getUri());
        self::assertSame('Bearer ' . self::TOKEN, $request->getHeaderLine('Authorization'));
    }

    public function testTusUploadReturnsSessionIdentifierAndPollDelegatesToResultClient(): void
    {
        $path = $this->writeArtifact('abc');
        $requests = [];
        $stack = $this->stack([
            new Response(201, ['Location' => self::BASE_URL . '/uploads/abc123', 'Upload-Offset' => '3'], ''),
        ], $requests);
        $resultRecording = RecordingTransport::withResponses([
            new Response(200, ['Content-Type' => 'application/json'], '{"status":"completed","cid":"QmTusCid"}'),
        ]);

        $adapter = $this->adapter(
            new PostUploader(RecordingTransport::withResponses([])->transport(), self::BASE_URL, self::TOKEN),
            $this->tus($stack),
            new UploadResultClient($resultRecording->transport(), self::BASE_URL, self::TOKEN),
        );

        $identifier = $adapter->upload($this->spec($path, UploadRoute::Tus, 3));

        self::assertSame($requests[0]->getHeaderLine('Upload-Key'), $identifier->value);
        self::assertSame('Bearer ' . self::TOKEN, $requests[0]->getHeaderLine('Authorization'));

        $result = $adapter->poll($identifier);

        self::assertInstanceOf(UploadResult::class, $result);
        self::assertTrue($result->isSuccess());
        self::assertSame(UploadStatus::Completed, $result->status);
        self::assertSame('QmTusCid', $result->cid);
        self::assertCount(1, $resultRecording->requests());

        $pollRequest = $resultRecording->lastRequest();
        self::assertSame('GET', $pollRequest->getMethod());
        self::assertSame(
            self::BASE_URL . '/api/upload/result/' . rawurlencode($identifier->value),
            (string) $pollRequest->getUri(),
        );
        self::assertSame('Bearer ' . self::TOKEN, $pollRequest->getHeaderLine('Authorization'));
    }

    public function testNon2xxOnPostMapsToSecretSafeUploadClientException(): void
    {
        $path = $this->writeArtifact('hello world');
        $postRecording = RecordingTransport::withResponses([
            new Response(422, [], '{"error":"denied s3cret-bearer-token-9f8d"}'),
        ]);
        $unusedRequests = [];

        $adapter = $this->adapter(
            new PostUploader($postRecording->transport(), self::BASE_URL, self::TOKEN),
            $this->tus($this->stack([], $unusedRequests)),
            new UploadResultClient(RecordingTransport::withResponses([])->transport(), self::BASE_URL, self::TOKEN),
        );

        try {
            $adapter->upload($this->spec($path, UploadRoute::Post, 11));
            self::fail('Expected UploadClientException.');
        } catch (UploadClientException $e) {
            self::assertGreaterThan(0, strlen((string) $e));
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, (string) $e);
            self::assertInstanceOf(\LumeWeb\Cast\Http\UnexpectedStatusCodeException::class, $e->getPrevious());
            self::assertSame(422, $e->getPrevious()->status());
        }
    }

    public function testRetryStartsWithFreshTusSessionIdentifier(): void
    {
        $path = $this->writeArtifact('abc');
        $requests = [];
        $stack = $this->stack([
            new Response(201, ['Location' => self::BASE_URL . '/uploads/a1', 'Upload-Offset' => '3'], ''),
            new Response(201, ['Location' => self::BASE_URL . '/uploads/a2', 'Upload-Offset' => '3'], ''),
        ], $requests);

        $adapter = $this->adapter(
            $this->postUploader(RecordingTransport::withResponses([])),
            $this->tus($stack),
            $this->resultClient(RecordingTransport::withResponses([])),
        );

        $first = $adapter->upload($this->spec($path, UploadRoute::Tus, 3));
        $second = $adapter->upload($this->spec($path, UploadRoute::Tus, 3));

        self::assertNotSame($first->value, $second->value, 'A retry must start a fresh Upload-Key, not reuse the lost session.');
        self::assertSame($requests[0]->getHeaderLine('Upload-Key'), $first->value);
        self::assertSame($requests[1]->getHeaderLine('Upload-Key'), $second->value);
    }

    public function testResumeDelegatesToTusWithExistingKeyAndKeepsIdentifier(): void
    {
        $path = $this->writeArtifact('abc');
        $key = 'resume-key-abc';
        $cache = $this->cache();
        $this->seedLocation($cache, $key, self::BASE_URL . '/uploads/resume1');

        $requests = [];
        $stack = $this->stack([
            new Response(200, ['Upload-Offset' => '3'], ''),
        ], $requests);

        $adapter = $this->adapter(
            $this->postUploader(RecordingTransport::withResponses([])),
            $this->tus($stack, $cache),
            $this->resultClient(RecordingTransport::withResponses([])),
        );

        $identifier = $adapter->resume($this->spec($path, UploadRoute::Tus, 3), new UploadIdentifier($key));

        self::assertSame($key, $identifier->value, 'Resume keeps the existing Upload-Key so the wait loop polls the same session.');
        self::assertCount(1, $requests);
        self::assertSame('HEAD', $requests[0]->getMethod(), 'Resume must HEAD the server offset, not re-create.');
        self::assertSame(self::BASE_URL . '/uploads/resume1', (string) $requests[0]->getUri());
        self::assertSame('Bearer ' . self::TOKEN, $requests[0]->getHeaderLine('Authorization'));
    }

    public function testCancelDelegatesToTusUploader(): void
    {
        $key = 'cancel-key-xyz';
        $cache = $this->cache();
        $this->seedLocation($cache, $key, self::BASE_URL . '/uploads/cancel1');

        $requests = [];
        $stack = $this->stack([
            new Response(204, [], ''),
        ], $requests);

        $adapter = $this->adapter(
            $this->postUploader(RecordingTransport::withResponses([])),
            $this->tus($stack, $cache),
            $this->resultClient(RecordingTransport::withResponses([])),
        );

        $adapter->cancel(new UploadIdentifier($key));

        self::assertCount(1, $requests);
        self::assertSame('DELETE', $requests[0]->getMethod());
        self::assertSame(self::BASE_URL . '/uploads/cancel1', (string) $requests[0]->getUri());
        self::assertSame('Bearer ' . self::TOKEN, $requests[0]->getHeaderLine('Authorization'));
    }

    public function testOffsetDelegatesToTusUploader(): void
    {
        $key = 'head-key-1';
        $cache = $this->cache();
        $this->seedLocation($cache, $key, self::BASE_URL . '/uploads/head1');

        $requests = [];
        $stack = $this->stack([
            new Response(200, ['Upload-Offset' => '6'], ''),
        ], $requests);

        $adapter = $this->adapter(
            $this->postUploader(RecordingTransport::withResponses([])),
            $this->tus($stack, $cache),
            $this->resultClient(RecordingTransport::withResponses([])),
        );

        self::assertSame(6, $adapter->offset(new UploadIdentifier($key)));
        self::assertCount(1, $requests);
        self::assertSame('HEAD', $requests[0]->getMethod());
        self::assertSame('Bearer ' . self::TOKEN, $requests[0]->getHeaderLine('Authorization'));
    }

    public function testMissingFileOnPostRouteMapsToSecretSafeUploadClientException(): void
    {
        $postRecording = RecordingTransport::withResponses([]);
        $adapter = $this->adapter(
            $this->postUploader($postRecording),
            $this->emptyTus(),
            $this->resultClient(RecordingTransport::withResponses([])),
        );

        try {
            $adapter->upload($this->spec($this->dir . '/missing.zip', UploadRoute::Post, 4));
            self::fail('Expected UploadClientException.');
        } catch (UploadClientException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, (string) $e);
            self::assertInstanceOf(UploadFileException::class, $e->getPrevious());
            self::assertSame(UploadFileProblem::NotFound, $e->getPrevious()->problem());
        }

        self::assertSame([], $postRecording->requests(), 'A missing file must fail before any request.');
    }

    public function testMissingFileOnTusRouteMapsToSecretSafeUploadClientException(): void
    {
        $requests = [];
        $adapter = $this->adapter(
            $this->postUploader(RecordingTransport::withResponses([])),
            $this->tus($this->stack([], $requests)),
            $this->resultClient(RecordingTransport::withResponses([])),
        );

        try {
            $adapter->upload($this->spec($this->dir . '/missing.zip', UploadRoute::Tus, 4));
            self::fail('Expected UploadClientException.');
        } catch (UploadClientException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, (string) $e);
            self::assertInstanceOf(UploadFileException::class, $e->getPrevious());
            self::assertSame(UploadFileProblem::NotFound, $e->getPrevious()->problem());
        }

        self::assertSame([], $requests, 'A missing file must fail before any request.');
    }

    public function testTransportErrorOnPostMapsToSecretSafeUploadClientException(): void
    {
        $path = $this->writeArtifact('hello world');
        $postRecording = RecordingTransport::withResponses([
            new ConnectException('Connection refused', new Request('POST', self::BASE_URL . '/api/upload')),
        ]);

        $adapter = $this->adapter(
            $this->postUploader($postRecording),
            $this->emptyTus(),
            $this->resultClient(RecordingTransport::withResponses([])),
        );

        try {
            $adapter->upload($this->spec($path, UploadRoute::Post, 11));
            self::fail('Expected UploadClientException.');
        } catch (UploadClientException $e) {
            self::assertStringContainsString('Transport error on POST', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, (string) $e);
            self::assertInstanceOf(TransportException::class, $e->getPrevious());
        }
    }

    public function testServerErrorOnTusCreateMapsToSecretSafeUploadClientException(): void
    {
        $path = $this->writeArtifact('abc');
        $requests = [];
        $stack = $this->stack([
            new Response(401, [], '{"error":"unauthorized"}'),
        ], $requests);

        $adapter = $this->adapter(
            $this->postUploader(RecordingTransport::withResponses([])),
            $this->tus($stack),
            $this->resultClient(RecordingTransport::withResponses([])),
        );

        try {
            $adapter->upload($this->spec($path, UploadRoute::Tus, 3));
            self::fail('Expected UploadClientException.');
        } catch (UploadClientException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, (string) $e);
            self::assertInstanceOf(TusUploadException::class, $e->getPrevious());
        }

        self::assertCount(1, $requests);
        self::assertSame('Bearer ' . self::TOKEN, $requests[0]->getHeaderLine('Authorization'));
    }

    public function testRedirectPolicyErrorOnPostMapsToSecretSafeUploadClientException(): void
    {
        $path = $this->writeArtifact('hello world');
        $postRecording = RecordingTransport::withResponses([
            new Response(308, [], ''),
        ]);

        $adapter = $this->adapter(
            $this->postUploader($postRecording),
            $this->emptyTus(),
            $this->resultClient(RecordingTransport::withResponses([])),
        );

        try {
            $adapter->upload($this->spec($path, UploadRoute::Post, 11));
            self::fail('Expected UploadClientException.');
        } catch (UploadClientException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, (string) $e);
            self::assertInstanceOf(UploadRedirectException::class, $e->getPrevious());
        }
    }

    public function testPollServerErrorMapsToSecretSafeUploadClientException(): void
    {
        $resultRecording = RecordingTransport::withResponses([
            new Response(500, [], '{"error":"boom"}'),
        ]);

        $adapter = $this->adapter(
            $this->postUploader(RecordingTransport::withResponses([])),
            $this->emptyTus(),
            $this->resultClient($resultRecording),
        );

        try {
            $adapter->poll(new UploadIdentifier('some-tus-key'));
            self::fail('Expected UploadClientException.');
        } catch (UploadClientException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, (string) $e);
            self::assertInstanceOf(UnexpectedStatusCodeException::class, $e->getPrevious());
            self::assertSame(500, $e->getPrevious()->status());
        }
    }

    public function testPollTransportErrorMapsToSecretSafeUploadClientException(): void
    {
        $resultRecording = RecordingTransport::withResponses([
            new ConnectException('Connection refused', new Request('GET', self::BASE_URL . '/api/upload/result/id-1')),
        ]);

        $adapter = $this->adapter(
            $this->postUploader(RecordingTransport::withResponses([])),
            $this->emptyTus(),
            $this->resultClient($resultRecording),
        );

        try {
            $adapter->poll(new UploadIdentifier('id-1'));
            self::fail('Expected UploadClientException.');
        } catch (UploadClientException $e) {
            self::assertStringContainsString('Transport error on GET', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, (string) $e);
            self::assertInstanceOf(TransportException::class, $e->getPrevious());
        }
    }

    public function testPollDecodingErrorMapsToSecretSafeUploadClientException(): void
    {
        $resultRecording = RecordingTransport::withResponses([
            new Response(200, ['Content-Type' => 'application/json'], 'not-json{{'),
        ]);

        $adapter = $this->adapter(
            $this->postUploader(RecordingTransport::withResponses([])),
            $this->emptyTus(),
            $this->resultClient($resultRecording),
        );

        try {
            $adapter->poll(new UploadIdentifier('id-1'));
            self::fail('Expected UploadClientException.');
        } catch (UploadClientException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, (string) $e);
            self::assertInstanceOf(ResponseDecodingException::class, $e->getPrevious());
        }
    }

    public function testResumeServerErrorMapsToSecretSafeUploadClientException(): void
    {
        $path = $this->writeArtifact('abc');
        $key = 'gone-key';
        $cache = $this->cache();
        $this->seedLocation($cache, $key, self::BASE_URL . '/uploads/gone1');

        $requests = [];
        $stack = $this->stack([
            new Response(500, [], ''),
        ], $requests);

        $adapter = $this->adapter(
            $this->postUploader(RecordingTransport::withResponses([])),
            $this->tus($stack, $cache),
            $this->resultClient(RecordingTransport::withResponses([])),
        );

        try {
            $adapter->resume($this->spec($path, UploadRoute::Tus, 3), new UploadIdentifier($key));
            self::fail('Expected UploadClientException.');
        } catch (UploadClientException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, (string) $e);
            self::assertInstanceOf(TusUploadException::class, $e->getPrevious());
        }
    }

    private function writeArtifact(string $contents): string
    {
        $path = $this->dir . '/site.zip';
        file_put_contents($path, $contents);

        return $path;
    }

    private function spec(string $path, UploadRoute $route, int $size): UploadSpec
    {
        return new UploadSpec($path, 'site.zip', archive: true, sizeBytes: $size, route: $route);
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

    private function tus(HandlerStack $stack, ?Cacheable $cache = null): TusUploader
    {
        return new TusUploader(
            baseUrl: self::BASE_URL,
            bearerToken: self::TOKEN,
            cache: $cache ?? $this->cache(),
            clientFactory: fn (string $baseUrl, array $options): Client => new Client($baseUrl, ['handler' => $stack] + $options),
        );
    }

    /**
     * A TusUploader that is never exercised (no queued responses); used to fill
     * the adapter slot in POST/poll-only tests.
     */
    private function emptyTus(): TusUploader
    {
        $unused = [];

        return $this->tus($this->stack([], $unused));
    }

    private function postUploader(RecordingTransport $recording): PostUploader
    {
        return new PostUploader($recording->transport(), self::BASE_URL, self::TOKEN);
    }

    private function resultClient(RecordingTransport $recording): UploadResultClient
    {
        return new UploadResultClient($recording->transport(), self::BASE_URL, self::TOKEN);
    }

    private function adapter(PostUploader $post, TusUploader $tus, UploadResultClient $results): IpfsUploadClient
    {
        return new IpfsUploadClient($post, $tus, $results);
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
