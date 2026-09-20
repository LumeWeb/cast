<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Portal;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Http\TransportException;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use LumeWeb\Cast\Portal\PortalAuthKeyClient;
use LumeWeb\Cast\Tests\Unit\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PortalAuthKeyClient maps the account-service API-key exchange
 * (POST /api/auth/key) to a login-purpose auth key. account.pinner.xyz only
 * accepts that auth key on /api/account, so the workspace API key
 * (PORTAL_API_KEY, aud=api) must be exchanged for the returned JWT first. The
 * API key only ever appears on the wire and must never leak into error text.
 */
final class PortalAuthKeyClientTest extends TestCase
{
    private const BASE_URL = 'https://account.pinner.xyz:8443';
    private const API_KEY = 's3cret-account-key-9f8d';

    /**
     * @param list<ResponseInterface|ClientExceptionInterface> $queue
     */
    private function client(array $queue): PortalAuthKeyClient
    {
        return new PortalAuthKeyClient(RecordingTransport::withResponses($queue)->transport(), self::BASE_URL);
    }

    public function testExchangePostsApiKeyToAuthKeyPathAndReturnsToken(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, ['Content-Type' => 'application/json'], '{"token":"exchanged-login-jwt"}'),
        ]);
        $client = new PortalAuthKeyClient($recording->transport(), self::BASE_URL);

        $authKey = $client->exchange(self::API_KEY);

        self::assertSame('exchanged-login-jwt', $authKey);

        $request = $recording->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/auth/key', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('', (string) $request->getBody());
    }

    public function testExchange401PreservesTypedStatusExceptionWithoutLeakingKey(): void
    {
        $client = $this->client([
            new Response(401, [], '{"error":"invalid API key"}'),
        ]);

        try {
            $client->exchange(self::API_KEY);
            self::fail('Expected UnexpectedStatusCodeException');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(401, $e->status());
            self::assertStringContainsString('401', $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
    }

    public function testExchangeStatusErrorBodyNeverLeaksKey(): void
    {
        $client = $this->client([
            new Response(403, [], '{"error":"forbidden"}'),
        ]);

        try {
            $client->exchange(self::API_KEY);
            self::fail('Expected UnexpectedStatusCodeException');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->response()->body());
        }
    }

    public function testExchangeMalformedBodyThrowsResponseDecodingException(): void
    {
        $client = $this->client([
            new Response(200, [], '<html>not json</html>'),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $client->exchange(self::API_KEY);
    }

    public function testExchangeEmptyBodyThrowsResponseDecodingException(): void
    {
        $client = $this->client([
            new Response(200, [], ''),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $client->exchange(self::API_KEY);
    }

    public function testExchangeMissingTokenFieldThrowsResponseDecodingExceptionWithoutLeakingKey(): void
    {
        $client = $this->client([
            new Response(200, [], '{"otp":false}'),
        ]);

        try {
            $client->exchange(self::API_KEY);
            self::fail('Expected ResponseDecodingException');
        } catch (ResponseDecodingException $e) {
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
    }

    public function testExchangeTransportFailureWrapsErrorWithoutLeakingKey(): void
    {
        $connect = new ConnectException(
            'Connection refused',
            new Request('POST', self::BASE_URL . '/api/auth/key'),
        );
        $client = $this->client([$connect]);

        try {
            $client->exchange(self::API_KEY);
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertStringContainsString('POST', $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
    }
}
