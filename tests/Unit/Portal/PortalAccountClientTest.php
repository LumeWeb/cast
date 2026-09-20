<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Portal;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Http\TransportException;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use LumeWeb\Cast\Portal\Account;
use LumeWeb\Cast\Portal\PortalAccountClient;
use LumeWeb\Cast\Tests\Unit\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PortalAccountClient maps the portal-sdk GetAccount (/api/account) response to
 * the Account identity DTO on the shared PSR-18 transport. Bearer credentials
 * must only ever appear on the wire and must never leak into error text.
 */
final class PortalAccountClientTest extends TestCase
{
    private const BASE_URL = 'https://account.pinner.xyz:8443';
    private const API_KEY = 's3cret-account-key-9f8d';

    /**
     * @param list<ResponseInterface|ClientExceptionInterface> $queue
     */
    private function client(array $queue): PortalAccountClient
    {
        $recording = RecordingTransport::withResponses($queue);

        return new PortalAccountClient($recording->transport(), self::BASE_URL, self::API_KEY);
    }

    public function testGetAccountSendsGetWithBearerToAccountPath(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, ['Content-Type' => 'application/json'], '{"id":7,"email":"a@b.test","first_name":"Ada","last_name":"Lovelace","verified":true,"otp":false}'),
        ]);
        $client = new PortalAccountClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->getAccount();

        $request = $recording->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/account', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('', (string) $request->getBody());
    }

    public function testGetAccountMapsAccountIdentityFields(): void
    {
        $client = $this->client([
            new Response(200, [], '{"id":7,"email":"a@b.test","first_name":"Ada","last_name":"Lovelace","verified":true,"otp":false}'),
        ]);

        $account = $client->getAccount();

        self::assertInstanceOf(Account::class, $account);
        self::assertSame(7, $account->id());
        self::assertSame('a@b.test', $account->email());
        self::assertSame('Ada', $account->firstName());
        self::assertSame('Lovelace', $account->lastName());
        self::assertTrue($account->verified());
    }

    public function testGetAccountReturnsUnverifiedIdentity(): void
    {
        $client = $this->client([
            new Response(200, [], '{"id":3,"email":"u@x.test","first_name":"Grace","last_name":"Hopper","verified":false,"otp":true}'),
        ]);

        self::assertFalse($client->getAccount()->verified());
    }

    public function testGetAccount401PreservesTypedStatusExceptionWithoutLeakingKey(): void
    {
        $client = $this->client([
            new Response(401, [], '{"error":"invalid token"}'),
        ]);

        try {
            $client->getAccount();
            self::fail('Expected UnexpectedStatusCodeException');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(401, $e->status());
            self::assertStringContainsString('401', $e->getMessage());
        }
    }

    public function testGetAccountStatusErrorBodyNeverLeaksKey(): void
    {
        $client = $this->client([
            new Response(403, [], '{"error":"forbidden"}'),
        ]);

        try {
            $client->getAccount();
            self::fail('Expected UnexpectedStatusCodeException');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->response()->body());
        }
    }

    public function testGetAccountMalformedBodyThrowsResponseDecodingException(): void
    {
        $client = $this->client([
            new Response(200, [], '<html>not json</html>'),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $client->getAccount();
    }

    public function testGetAccountEmptyBodyThrowsResponseDecodingException(): void
    {
        $client = $this->client([
            new Response(200, [], ''),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $client->getAccount();
    }

    public function testGetAccountMissingRequiredFieldThrowsResponseDecodingException(): void
    {
        $client = $this->client([
            new Response(200, [], '{"id":7,"first_name":"Ada","last_name":"Lovelace","verified":true,"otp":false}'),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $client->getAccount();
    }

    public function testGetAccountWrongTypeFieldThrowsResponseDecodingException(): void
    {
        $client = $this->client([
            new Response(200, [], '{"id":"7","email":"a@b.test","first_name":"Ada","last_name":"Lovelace","verified":true,"otp":false}'),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $client->getAccount();
    }

    public function testGetAccountTransportFailureWrapsErrorWithoutLeakingKey(): void
    {
        $connect = new ConnectException(
            'Connection refused',
            new Request('GET', self::BASE_URL . '/api/account'),
        );
        $client = $this->client([$connect]);

        try {
            $client->getAccount();
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertStringContainsString('GET', $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
    }
}
