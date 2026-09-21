<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Upload;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Http\TransportException;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Tests\Unit\Support\RecordingTransport;
use LumeWeb\Cast\Publish\UploadRoute;
use LumeWeb\Cast\Publish\UploadSpec;
use LumeWeb\Cast\Publish\UploadStatus;
use LumeWeb\Cast\Upload\PostUploader;
use LumeWeb\Cast\Upload\RedirectLoopException;
use LumeWeb\Cast\Upload\TooManyRedirectsException;
use LumeWeb\Cast\Upload\UploadFileException;
use LumeWeb\Cast\Upload\UploadFileProblem;
use LumeWeb\Cast\Upload\UploadRedirectException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The real small-upload POST adapter against the ipfs-sdk contract: a single
 * multipart field named "file" is streamed to /api/upload with the archive
 * flag and name as query parameters, the PostUploadResponse CID is mapped to a
 * Publish UploadResult, and the adapter owns the 307/308 redirect policy (hop
 * budget, loop detection, body preservation, bearer never leaked). File
 * problems are typed and never reveal the bearer token.
 */
final class PostUploaderTest extends TestCase
{
    private const TOKEN = 's3cret-bearer-token-9f8d';
    private const BASE_URL = 'https://portal.test';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cast-upload-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->dir, 0777, true));
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            $this->removeDir($this->dir);
        }
    }

    public function testStreamsMultipartFileWithArchiveQueryNameAndBearer(): void
    {
        $contents = 'hello-zip-artifact';
        $path = $this->writeArtifact($contents);
        $recording = RecordingTransport::withResponses([
            new Response(200, ['Content-Type' => 'application/json'], '{"CID":"QmSuccess"}'),
        ]);

        $result = $this->poster($recording)->upload($this->spec($path, true, 'site.zip', strlen($contents)));

        self::assertSame(UploadStatus::Completed, $result->status);
        self::assertSame('QmSuccess', $result->cid);
        self::assertSame(strlen($contents), $result->size);

        $request = $recording->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            $this->baseUrl() . '/api/upload?archive=true&name=site.zip',
            (string) $request->getUri(),
        );
        self::assertSame('Bearer ' . self::TOKEN, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));

        $contentType = $request->getHeaderLine('Content-Type');
        self::assertStringStartsWith('multipart/form-data; boundary=', $contentType);
        $boundary = substr($contentType, strlen('multipart/form-data; boundary='));

        $body = (string) $request->getBody();
        self::assertStringStartsWith('--' . $boundary, $body);
        self::assertStringContainsString('name="file"', $body);
        self::assertStringContainsString('filename="site.zip"', $body);
        self::assertStringContainsString($contents, $body);
        self::assertStringEndsWith('--' . $boundary . "--\r\n", $body);
    }

    public function testUsesArchiveFalseQueryWhenArchiveFlagIsFalse(): void
    {
        $path = $this->writeArtifact('raw');
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"CID":"QmRaw"}'),
        ]);

        $this->poster($recording)->upload($this->spec($path, false, 'site.zip', 3));

        self::assertSame(
            $this->baseUrl() . '/api/upload?archive=false&name=site.zip',
            (string) $recording->lastRequest()->getUri(),
        );
    }

    public function testOmitNameQueryWhenNameIsEmpty(): void
    {
        $path = $this->writeArtifact('raw');
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"CID":"QmRaw"}'),
        ]);

        $this->poster($recording)->upload($this->spec($path, true, '', 3));

        $uri = (string) $recording->lastRequest()->getUri();
        self::assertStringContainsString('archive=true', $uri);
        self::assertStringNotContainsString('name=', $uri);
    }

    public function testReportsUploadSizeFromSpec(): void
    {
        $path = $this->writeArtifact('tiny');
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"CID":"QmSized"}'),
        ]);

        $result = $this->poster($recording)->upload($this->spec($path, true, 'site.zip', 12345));

        self::assertSame(12345, $result->size);
        self::assertSame('QmSized', $result->cid);
    }

    public function testFollowsRelativeTemporaryRedirectPreservingMethodBodyAndBearer(): void
    {
        $contents = 'redirect-me';
        $path = $this->writeArtifact($contents);
        $recording = RecordingTransport::withResponses([
            new Response(307, ['Location' => '/real-upload']),
            new Response(200, [], '{"CID":"QmFollowed"}'),
        ]);

        $result = $this->poster($recording)->upload($this->spec($path, true, 'site.zip', strlen($contents)));

        self::assertSame('QmFollowed', $result->cid);

        $requests = $recording->requests();
        self::assertCount(2, $requests);
        self::assertSame('https://portal.test/real-upload', (string) $requests[1]->getUri());
        $this->assertCarriesSameMultipartBody($requests[1], 'file', 'site.zip', $contents);
        self::assertSame('POST', $requests[1]->getMethod());
        self::assertSame('Bearer ' . self::TOKEN, $requests[1]->getHeaderLine('Authorization'));
    }

    public function testFollowsAbsoluteTemporaryRedirectAndReturnsCid(): void
    {
        $path = $this->writeArtifact('data');
        $recording = RecordingTransport::withResponses([
            new Response(307, ['Location' => 'https://cdn.test/ingest']),
            new Response(200, [], '{"CID":"QmAbs"}'),
        ]);

        $result = $this->poster($recording)->upload($this->spec($path, true, 'site.zip', 4));

        self::assertSame('QmAbs', $result->cid);
        self::assertSame('https://cdn.test/ingest', (string) $recording->lastRequest()->getUri());
    }

    public function testFollowsPermanentRedirect(): void
    {
        $path = $this->writeArtifact('data');
        $recording = RecordingTransport::withResponses([
            new Response(308, ['Location' => 'https://portal.test/v2/upload']),
            new Response(200, [], '{"CID":"Qm308"}'),
        ]);

        $result = $this->poster($recording)->upload($this->spec($path, true, 'site.zip', 4));

        self::assertSame('Qm308', $result->cid);
        self::assertSame('https://portal.test/v2/upload', (string) $recording->lastRequest()->getUri());
    }

    public function testStopsOnRedirectLoopWithTypedError(): void
    {
        $path = $this->writeArtifact('data');
        $loopTarget = $this->baseUrl() . '/api/upload?archive=true&name=site.zip';
        $recording = RecordingTransport::withResponses([
            new Response(307, ['Location' => $loopTarget]),
        ]);

        try {
            $this->poster($recording)->upload($this->spec($path, true, 'site.zip', 4));
            self::fail('Expected RedirectLoopException');
        } catch (RedirectLoopException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }

        self::assertCount(1, $recording->requests());
    }

    public function testExhaustsRedirectHopBudgetWithTypedError(): void
    {
        $path = $this->writeArtifact('data');
        $recording = RecordingTransport::withResponses([
            $this->redirectTo('/hop-1'),
            $this->redirectTo('/hop-2'),
            $this->redirectTo('/hop-3'),
            $this->redirectTo('/hop-4'),
            $this->redirectTo('/hop-5'),
        ]);

        try {
            $this->poster($recording)->upload($this->spec($path, true, 'site.zip', 4));
            self::fail('Expected TooManyRedirectsException');
        } catch (TooManyRedirectsException $e) {
            self::assertStringContainsString('5', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }

        self::assertCount(5, $recording->requests());
    }

    public function testDoesNotFollowOtherRedirectStatuses(): void
    {
        $path = $this->writeArtifact('data');
        $recording = RecordingTransport::withResponses([
            new Response(302, ['Location' => 'https://portal.test/elsewhere']),
        ]);

        try {
            $this->poster($recording)->upload($this->spec($path, true, 'site.zip', 4));
            self::fail('Expected UnexpectedStatusCodeException');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(302, $e->status());
        }

        self::assertCount(1, $recording->requests());
    }

    public function testMissingLocationOnRedirectRaisesTypedError(): void
    {
        $path = $this->writeArtifact('data');
        $recording = RecordingTransport::withResponses([
            new Response(307),
        ]);

        try {
            $this->poster($recording)->upload($this->spec($path, true, 'site.zip', 4));
            self::fail('Expected UploadRedirectException');
        } catch (UploadRedirectException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testRejectsNon2xxWithTypedErrorWithoutLeakingBearer(): void
    {
        $path = $this->writeArtifact('data');
        $recording = RecordingTransport::withResponses([
            new Response(500, ['Content-Type' => 'application/json'], '{"error":"boom"}'),
        ]);

        try {
            $this->poster($recording)->upload($this->spec($path, true, 'site.zip', 4));
            self::fail('Expected UnexpectedStatusCodeException');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(500, $e->status());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->response()->body());
        }

        self::assertSame('Bearer ' . self::TOKEN, $recording->lastRequest()->getHeaderLine('Authorization'));
    }

    public function testTransportFailureIsTypedAndNeverLeaksBearer(): void
    {
        $path = $this->writeArtifact('data');
        $connect = new ConnectException('Connection refused', new Request('POST', $this->baseUrl() . '/api/upload'));
        $recording = RecordingTransport::withResponses([$connect]);

        try {
            $this->poster($recording)->upload($this->spec($path, true, 'site.zip', 4));
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testMalformedUploadResponseRaisesDecodingError(): void
    {
        $path = $this->writeArtifact('data');
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"some":"other"}'),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $this->poster($recording)->upload($this->spec($path, true, 'site.zip', 4));
    }

    public function testFileNotFoundIsTyped(): void
    {
        $missing = $this->dir . '/does-not-exist.zip';
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"CID":"QmNever"}'),
        ]);

        try {
            $this->poster($recording)->upload($this->spec($missing, true, 'missing.zip', 4));
            self::fail('Expected UploadFileException');
        } catch (UploadFileException $e) {
            self::assertSame(UploadFileProblem::NotFound, $e->problem());
            self::assertSame($missing, $e->path());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }

        self::assertCount(0, $recording->requests());
    }

    public function testEmptyFileIsTyped(): void
    {
        $path = $this->writeArtifact('');
        $recording = RecordingTransport::withResponses([]);

        try {
            $this->poster($recording)->upload($this->spec($path, true, 'empty.zip', 0));
            self::fail('Expected UploadFileException');
        } catch (UploadFileException $e) {
            self::assertSame(UploadFileProblem::Empty, $e->problem());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }

        self::assertCount(0, $recording->requests());
    }

    public function testUnreadableFileIsTyped(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('A chmod()-gated unreadable file cannot be simulated when running as root.');
        }

        $path = $this->writeArtifact('secret');
        chmod($path, 0000);
        try {
            $recording = RecordingTransport::withResponses([]);

            try {
                $this->poster($recording)->upload($this->spec($path, true, 'secret.zip', 6));
                self::fail('Expected UploadFileException');
            } catch (UploadFileException $e) {
                self::assertSame(UploadFileProblem::Unreadable, $e->problem());
                self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            }

            self::assertCount(0, $recording->requests());
        } finally {
            chmod($path, 0o644);
        }
    }

    private function poster(RecordingTransport $recording): PostUploader
    {
        return new PostUploader($recording->transport(), self::BASE_URL, self::TOKEN);
    }

    private function spec(string $path, bool $archive, string $name, int $size): UploadSpec
    {
        return new UploadSpec($path, $name, $archive, $size, UploadRoute::Post);
    }

    private function baseUrl(): string
    {
        return self::BASE_URL;
    }

    private function redirectTo(string $location): Response
    {
        return new Response(307, ['Location' => $location]);
    }

    private function assertCarriesSameMultipartBody(RequestInterface $request, string $field, string $filename, string $contents): void
    {
        $contentType = $request->getHeaderLine('Content-Type');
        self::assertStringStartsWith('multipart/form-data; boundary=', $contentType);
        $boundary = substr($contentType, strlen('multipart/form-data; boundary='));

        $body = (string) $request->getBody();
        self::assertStringStartsWith('--' . $boundary, $body);
        self::assertStringContainsString('name="' . $field . '"', $body);
        self::assertStringContainsString('filename="' . $filename . '"', $body);
        self::assertStringContainsString($contents, $body);
    }

    private function writeArtifact(string $contents): string
    {
        $path = $this->dir . '/site.zip';
        file_put_contents($path, $contents);

        return $path;
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
