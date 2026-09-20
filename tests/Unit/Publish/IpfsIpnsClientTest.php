<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Http\TransportException;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use LumeWeb\Cast\Ipfs\IpfsIpnsClient as SdkIpnsClient;
use LumeWeb\Cast\Publish\IpfsIpnsClient;
use LumeWeb\Cast\Publish\IpnsClientException;
use LumeWeb\Cast\Publish\IpnsKey;
use LumeWeb\Cast\Publish\IpnsPublication;
use LumeWeb\Cast\Tests\Unit\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The Publish IpnsClient adapter over the ipfs-sdk IPO client: createKey()
 * delegates to the SDK's POST /api/ipns/keys and maps the numeric portal key
 * id onto the Publish IpnsKey; publish() resolves the friendly key name back
 * to that numeric id through the PublishRegistry's SitePublishState, delegates
 * the POST /api/ipns/publish with the id intact, and maps the response onto
 * the Publish IpnsPublication. Every typed HttpException is rethrown as an
 * IpnsClientException carrying the original as $previous; the message is
 * always secret-safe because the bearer API key only ever appears on the wire
 * and never in the typed error text being wrapped.
 */
final class IpfsIpnsClientTest extends TestCase
{
    private const BASE_URL = 'https://ipfs.pinner.xyz';
    private const API_KEY = 's3cret-portal-key-91e3';

    /**
     * Builds the adapter on a recording transport with a fake registry seeded
     * from the given key identity, returning [recording, adapter].
     *
     * @param list<ResponseInterface|ClientExceptionInterface> $queue
     *
     * @return array{0: RecordingTransport, 1: IpfsIpnsClient}
     */
    private function build(array $queue, ?string $keyName = null, ?string $keyId = null): array
    {
        $recording = RecordingTransport::withResponses($queue);
        $registry = new FakePublishRegistry();
        $registry->seed(websiteId: 'website-1', ipnsKey: $keyName, ipnsKeyId: $keyId);
        $sdk = new SdkIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        return [$recording, new IpfsIpnsClient($sdk, $registry)];
    }

    public function testCreateKeyDelegatesToSdkAndMapsNumericKeyIdAndName(): void
    {
        [$recording, $adapter] = $this->build([new Response(201, [], $this->keyResponse())]);

        $key = $adapter->createKey('mysite');

        self::assertInstanceOf(IpnsKey::class, $key);
        self::assertSame('mysite', $key->name);
        self::assertSame('42', $key->id);

        $request = $recording->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/ipns/keys', (string) $request->getUri());
        self::assertSame('{"name":"mysite"}', (string) $request->getBody());
        self::assertCount(1, $recording->requests());
    }

    public function testPublishResolvesKeyNameToNumericIdFromRegistryAndDelegatesWithIntKeyId(): void
    {
        [$recording, $adapter] = $this->build(
            [new Response(200, [], $this->publicationResponse())],
            'mysite',
            '42',
        );

        $publication = $adapter->publish('mysite', 'QmZ');

        self::assertInstanceOf(IpnsPublication::class, $publication);
        self::assertSame('mysite', $publication->keyName);
        self::assertSame('QmZ', $publication->cid);
        self::assertSame('k51qzi5uqu5djg', $publication->ipnsName);

        $request = $recording->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/ipns/publish', (string) $request->getUri());
        self::assertSame('{"key_id":42,"cid":"QmZ"}', (string) $request->getBody());
        self::assertCount(1, $recording->requests());
    }

    public function testPublishWithoutRecordedKeyThrowsIpnsClientExceptionWithoutHittingNetwork(): void
    {
        [$recording, $adapter] = $this->build([], null, null);

        try {
            $adapter->publish('missing-key', 'QmZ');
            self::fail('Expected IpnsClientException.');
        } catch (IpnsClientException $e) {
            self::assertStringContainsString('missing-key', $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }

        self::assertCount(0, $recording->requests());
    }

    public function testPublishWithMismatchedRecordedKeyNameThrowsIpnsClientException(): void
    {
        [$recording, $adapter] = $this->build([], 'mysite', '42');

        try {
            $adapter->publish('some-other-key', 'QmZ');
            self::fail('Expected IpnsClientException.');
        } catch (IpnsClientException $e) {
            self::assertStringContainsString('some-other-key', $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }

        self::assertCount(0, $recording->requests());
    }

    public function testPublishWhenKeyIdWasNeverRecordedThrowsIpnsClientException(): void
    {
        [$recording, $adapter] = $this->build([], 'mysite', null);

        try {
            $adapter->publish('mysite', 'QmZ');
            self::fail('Expected IpnsClientException.');
        } catch (IpnsClientException $e) {
            self::assertStringContainsString('mysite', $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }

        self::assertCount(0, $recording->requests());
    }

    public function testCreateKeyNon2xxMapsToIpnsClientExceptionWithoutSecret(): void
    {
        [$recording, $adapter] = $this->build([
            new Response(409, [], '{"error":"key exists s3cret-portal-key-91e3"}'),
        ]);

        try {
            $adapter->createKey('mysite');
            self::fail('Expected IpnsClientException.');
        } catch (IpnsClientException $e) {
            self::assertGreaterThan(0, strlen((string) $e));
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(UnexpectedStatusCodeException::class, $e->getPrevious());
            self::assertSame(409, $e->getPrevious()->status());
        }

        self::assertCount(1, $recording->requests());
    }

    public function testPublishNon2xxMapsToIpnsClientExceptionWithoutSecret(): void
    {
        [$recording, $adapter] = $this->build(
            [new Response(500, [], '{"error":"boom s3cret-portal-key-91e3"}')],
            'mysite',
            '42',
        );

        try {
            $adapter->publish('mysite', 'QmZ');
            self::fail('Expected IpnsClientException.');
        } catch (IpnsClientException $e) {
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(UnexpectedStatusCodeException::class, $e->getPrevious());
            self::assertSame(500, $e->getPrevious()->status());
        }

        self::assertCount(1, $recording->requests());
    }

    public function testCreateKeyMalformedJsonMapsToIpnsClientExceptionWithDecodingPrevious(): void
    {
        [$recording, $adapter] = $this->build([new Response(201, [], 'not-json{{')]);

        try {
            $adapter->createKey('mysite');
            self::fail('Expected IpnsClientException.');
        } catch (IpnsClientException $e) {
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(ResponseDecodingException::class, $e->getPrevious());
        }
    }

    public function testCreateKeyTransportErrorMapsToIpnsClientExceptionWithoutSecret(): void
    {
        $recording = RecordingTransport::withResponses([
            new ConnectException('Connection refused', new Request('POST', self::BASE_URL . '/api/ipns/keys')),
        ]);
        $adapter = new IpfsIpnsClient(
            new SdkIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY),
            new FakePublishRegistry(),
        );

        try {
            $adapter->createKey('mysite');
            self::fail('Expected IpnsClientException.');
        } catch (IpnsClientException $e) {
            self::assertStringContainsString('Transport error on POST', $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(TransportException::class, $e->getPrevious());
        }
    }

    public function testPublishTransportErrorMapsToIpnsClientExceptionWithoutSecret(): void
    {
        $recording = RecordingTransport::withResponses([
            new ConnectException('Connection refused', new Request('POST', self::BASE_URL . '/api/ipns/publish')),
        ]);
        $registry = new FakePublishRegistry();
        $registry->seed(websiteId: 'website-1', ipnsKey: 'mysite', ipnsKeyId: '42');
        $adapter = new IpfsIpnsClient(
            new SdkIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY),
            $registry,
        );

        try {
            $adapter->publish('mysite', 'QmZ');
            self::fail('Expected IpnsClientException.');
        } catch (IpnsClientException $e) {
            self::assertStringContainsString('Transport error on POST', $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(TransportException::class, $e->getPrevious());
        }
    }

    private function keyResponse(): string
    {
        return '{"id":42,"name":"mysite","ipns_name":"k51qzi5uqu5djg",'
            . '"peer_id":"12D3KooWPeer","created":"2026-01-02T00:00:00Z",'
            . '"last_published_at":"2026-01-03T00:00:00Z","value":"/ipfs/QmCid"}';
    }

    private function publicationResponse(): string
    {
        return '{"name":"k51qzi5uqu5djg","value":"/ipfs/QmZ","sequence":7,'
            . '"published":"2026-01-04T00:00:00Z","validity":"2026-01-11T00:00:00Z"}';
    }
}
