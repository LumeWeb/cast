<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Upload;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Http\TransportException;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use LumeWeb\Cast\Publish\UploadIdentifier;
use LumeWeb\Cast\Publish\UploadStatus;
use LumeWeb\Cast\Tests\Unit\Support\RecordingTransport;
use LumeWeb\Cast\Upload\UploadResultClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The real upload-result adapter against the ipfs-sdk contract: GET
 * /api/upload/result/{identifier} with the identifier URL-path-escaped, bearer
 * auth, and the UploadResultResponse {status, cid?, error?} mapped onto the
 * Publish UploadResult/UploadStatus DTOs. Failure detail (error on a failed
 * result) is preserved, unknown/malformed payloads fail loudly, and the typed
 * HttpException family is preserved without ever leaking the bearer token.
 */
final class UploadResultClientTest extends TestCase
{
    private const TOKEN = 's3cret-bearer-token-9f8d';
    private const BASE_URL = 'https://portal.test';

    public function testGetsResultWithEscapedIdentifierPathAndBearer(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, ['Content-Type' => 'application/json'], '{"status":"completed","cid":"QmDone"}'),
        ]);

        $result = $this->client($recording)->poll(new UploadIdentifier('tus/abc 123#frag'));

        self::assertSame(UploadStatus::Completed, $result->status);
        self::assertSame('QmDone', $result->cid);
        self::assertNull($result->message);

        $request = $recording->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(
            self::BASE_URL . '/api/upload/result/tus%2Fabc%20123%23frag',
            (string) $request->getUri(),
        );
        self::assertSame('Bearer ' . self::TOKEN, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function testSendsNumericRequestIdAsIs(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"status":"completed","cid":"QmNum"}'),
        ]);

        $this->client($recording)->poll(new UploadIdentifier('42'));

        self::assertSame(
            self::BASE_URL . '/api/upload/result/42',
            (string) $recording->lastRequest()->getUri(),
        );
    }

    public function testMapsPendingStatus(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"status":"pending"}'),
        ]);

        $result = $this->client($recording)->poll(new UploadIdentifier('42'));

        self::assertSame(UploadStatus::Pending, $result->status);
        self::assertNull($result->cid);
        self::assertNull($result->message);
    }

    public function testMapsProcessingStatus(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"status":"processing"}'),
        ]);

        $result = $this->client($recording)->poll(new UploadIdentifier('42'));

        self::assertSame(UploadStatus::Processing, $result->status);
    }

    public function testMapsDuplicateStatusToTerminalSuccess(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"status":"duplicate","cid":"QmDup"}'),
        ]);

        $result = $this->client($recording)->poll(new UploadIdentifier('42'));

        self::assertSame(UploadStatus::Duplicate, $result->status);
        self::assertSame('QmDup', $result->cid);
        self::assertTrue($result->isSuccess());
    }

    public function testMapsFailedStatusPreservingErrorDetail(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"status":"failed","error":"archive unpack failed: corrupt zip"}'),
        ]);

        $result = $this->client($recording)->poll(new UploadIdentifier('42'));

        self::assertSame(UploadStatus::Failed, $result->status);
        self::assertSame('archive unpack failed: corrupt zip', $result->message);
        self::assertFalse($result->isSuccess());
    }

    public function testFailedWithoutErrorDetailYieldsNullMessage(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"status":"failed"}'),
        ]);

        $result = $this->client($recording)->poll(new UploadIdentifier('42'));

        self::assertSame(UploadStatus::Failed, $result->status);
        self::assertNull($result->message);
    }

    public function testCompletedWithoutCidRaisesDecodingError(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"status":"completed"}'),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $this->client($recording)->poll(new UploadIdentifier('42'));
    }

    public function testDuplicateWithoutCidRaisesDecodingError(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"status":"duplicate"}'),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $this->client($recording)->poll(new UploadIdentifier('42'));
    }

    public function testUnknownStatusRaisesDecodingError(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"status":"weird"}'),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $this->client($recording)->poll(new UploadIdentifier('42'));
    }

    public function testMissingStatusRaisesDecodingError(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"cid":"QmNoStatus"}'),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $this->client($recording)->poll(new UploadIdentifier('42'));
    }

    public function testMalformedJsonRaisesDecodingError(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], 'not-json-at-all'),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $this->client($recording)->poll(new UploadIdentifier('42'));
    }

    public function testNonStringCidRaisesDecodingError(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"status":"completed","cid":123}'),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $this->client($recording)->poll(new UploadIdentifier('42'));
    }

    public function testNonStringErrorRaisesDecodingError(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"status":"failed","error":123}'),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $this->client($recording)->poll(new UploadIdentifier('42'));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function errorStatusProvider(): iterable
    {
        yield 'not found' => [404];
        yield 'server error' => [500];
    }

    #[DataProvider('errorStatusProvider')]
    public function testNon2xxRaisesTypedErrorWithoutLeakingBearer(int $status): void
    {
        $recording = RecordingTransport::withResponses([
            new Response($status, ['Content-Type' => 'application/json'], '{"error":{"reason":"boom"}}'),
        ]);

        try {
            $this->client($recording)->poll(new UploadIdentifier('42'));
            self::fail('Expected UnexpectedStatusCodeException');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame($status, $e->status());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->response()->body());
        }

        self::assertSame('Bearer ' . self::TOKEN, $recording->lastRequest()->getHeaderLine('Authorization'));
    }

    public function testTransportFailureIsTypedAndNeverLeaksBearer(): void
    {
        $connect = new ConnectException('Connection refused', new Request('GET', self::BASE_URL . '/api/upload/result/42'));
        $recording = RecordingTransport::withResponses([$connect]);

        try {
            $this->client($recording)->poll(new UploadIdentifier('42'));
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    private function client(RecordingTransport $recording): UploadResultClient
    {
        return new UploadResultClient($recording->transport(), self::BASE_URL, self::TOKEN);
    }
}
