<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Ipfs;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Http\TransportException;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use LumeWeb\Cast\Ipfs\IpfsIpnsClient;
use LumeWeb\Cast\Ipfs\IpnsKey;
use LumeWeb\Cast\Ipfs\IpnsPublication;
use LumeWeb\Cast\Tests\Unit\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * IpfsIpnsClient maps the ipfs-sdk IPNS CreateKey (POST /api/ipns/keys) and
 * Publish (POST /api/ipns/publish, keyed by the numeric key id) calls on the
 * shared PSR-18 transport, with exact JSON bodies, the bearer key only on the
 * wire, and the typed HttpException family preserved. Creating a key or
 * publishing never triggers an extra create; each call is exactly one request.
 */
final class IpfsIpnsClientTest extends TestCase
{
    private const BASE_URL = 'https://ipfs.pinner.xyz';
    private const API_KEY = 's3cret-portal-key-b7f1';

    /**
     * @param list<ResponseInterface|ClientExceptionInterface> $queue
     */
    private function recording(array $queue): RecordingTransport
    {
        return RecordingTransport::withResponses($queue);
    }

    public function testCreateKeySendsPostToKeysWithExactNameBodyAndBearer(): void
    {
        $recording = $this->recording([new Response(201, [], $this->keyResponse())]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $key = $client->createKey('mysite');

        $request = $recording->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/ipns/keys', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"name":"mysite"}', (string) $request->getBody());

        self::assertInstanceOf(IpnsKey::class, $key);
        self::assertSame(42, $key->id());
        self::assertSame('mysite', $key->name());
    }

    public function testCreateKeyMapsKeyIdNameAndPeerMetadata(): void
    {
        $recording = $this->recording([new Response(201, [], $this->keyResponse())]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $key = $client->createKey('mysite');

        self::assertSame(42, $key->id());
        self::assertSame('mysite', $key->name());
        self::assertSame('k51qzi5uqu5djg', $key->ipnsName());
        self::assertSame('12D3KooWPeer', $key->peerId());
        self::assertSame('2026-01-02T00:00:00Z', $key->created());
        self::assertSame('/ipfs/QmCid', $key->value());
        self::assertSame('2026-01-03T00:00:00Z', $key->lastPublishedAt());
    }

    public function testCreateKeyAcceptsOkStatusAsSdkDoes(): void
    {
        $recording = $this->recording([new Response(200, [], $this->keyResponse())]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $key = $client->createKey('mysite');

        self::assertSame(42, $key->id());
    }

    public function testCreateKeySendsExactlyOneRequest(): void
    {
        $recording = $this->recording([new Response(201, [], $this->keyResponse())]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->createKey('mysite');

        self::assertCount(1, $recording->requests());
    }

    public function testCreateKeyNon2xxThrowsUnexpectedStatusWithoutSecret(): void
    {
        $recording = $this->recording([
            new Response(409, [], '{"error":"key exists s3cret-portal-key-b7f1"}'),
        ]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->createKey('mysite');
            self::fail('Expected UnexpectedStatusCodeException.');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(409, $e->status());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    public function testCreateKeyMissingIdThrowsResponseDecoding(): void
    {
        $recording = $this->recording([
            new Response(201, [], '{"name":"mysite","ipns_name":"i","peer_id":"p","created":"c"}'),
        ]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $this->expectException(ResponseDecodingException::class);
        $this->expectExceptionMessage('IPNS key response is missing integer field "id".');

        $client->createKey('mysite');
    }

    public function testCreateKeyMalformedJsonThrowsResponseDecoding(): void
    {
        $recording = $this->recording([new Response(201, [], '{oops')]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $this->expectException(ResponseDecodingException::class);

        $client->createKey('mysite');
    }

    public function testCreateKeyTransportErrorIsTypedAndNeverLeaksSecret(): void
    {
        $recording = $this->recording([
            new ConnectException('Connection refused', new Request('POST', self::BASE_URL . '/api/ipns/keys')),
        ]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->createKey('mysite');
            self::fail('Expected TransportException.');
        } catch (TransportException $e) {
            self::assertSame('POST', $e->method());
            self::assertSame(self::BASE_URL . '/api/ipns/keys', $e->uri());
            self::assertSame(
                'Transport error on POST ' . self::BASE_URL . '/api/ipns/keys',
                $e->getMessage(),
            );
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    public function testPublishSendsPostToPublishWithKeyIdAndCidAndMapsResponse(): void
    {
        $recording = $this->recording([new Response(200, [], $this->publicationResponse())]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $publication = $client->publish(42, 'QmZ');

        $request = $recording->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/ipns/publish', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"key_id":42,"cid":"QmZ"}', (string) $request->getBody());

        self::assertInstanceOf(IpnsPublication::class, $publication);
        self::assertSame('mysite', $publication->name());
        self::assertSame('/ipfs/QmZ', $publication->value());
        self::assertSame(7, $publication->sequence());
        self::assertSame('2026-01-04T00:00:00Z', $publication->published());
        self::assertSame('2026-01-11T00:00:00Z', $publication->validity());
    }

    public function testPublishSendsExactlyOneRequest(): void
    {
        $recording = $this->recording([new Response(200, [], $this->publicationResponse())]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->publish(42, 'QmZ');

        self::assertCount(1, $recording->requests());
    }

    public function testPublishNon2xxThrowsUnexpectedStatusWithoutSecret(): void
    {
        $recording = $this->recording([
            new Response(500, [], '{"error":"boom s3cret-portal-key-b7f1"}'),
        ]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->publish(42, 'QmZ');
            self::fail('Expected UnexpectedStatusCodeException.');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(500, $e->status());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    public function testPublishMalformedBodyThrowsResponseDecoding(): void
    {
        $recording = $this->recording([new Response(200, [], '')]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $this->expectException(ResponseDecodingException::class);

        $client->publish(42, 'QmZ');
    }

    public function testPublishTransportErrorIsTypedAndNeverLeaksSecret(): void
    {
        $recording = $this->recording([
            new ConnectException('Connection refused', new Request('POST', self::BASE_URL . '/api/ipns/publish')),
        ]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->publish(42, 'QmZ');
            self::fail('Expected TransportException.');
        } catch (TransportException $e) {
            self::assertSame(
                'Transport error on POST ' . self::BASE_URL . '/api/ipns/publish',
                $e->getMessage(),
            );
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
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
        return '{"name":"mysite","value":"/ipfs/QmZ","sequence":7,'
            . '"published":"2026-01-04T00:00:00Z","validity":"2026-01-11T00:00:00Z"}';
    }

    /* ------------------------------ resolve ------------------------------ */

    public function testResolveSendsGetToResolveNameWithReadHeadersAndMapsResponse(): void
    {
        $recording = $this->recording([new Response(200, [], $this->resolutionResponse())]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $resolution = $client->resolve('k51qzi5uqu5djg');

        $request = $recording->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/ipns/resolve/k51qzi5uqu5djg', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        // A read call carries no Content-Type, matching the read convention.
        self::assertSame('', $request->getHeaderLine('Content-Type'));

        self::assertSame('k51qzi5uqu5djg', $resolution->name());
        self::assertSame('/ipfs/QmZ', $resolution->value());
        self::assertSame('/ipfs/QmZ', $resolution->path());
        self::assertSame(7, $resolution->sequence());
        self::assertFalse($resolution->expired());
        self::assertSame('2026-01-11T00:00:00Z', $resolution->expires());
        // The immutable root CID is derived from the /ipfs/ path.
        self::assertSame('QmZ', $resolution->cid());
    }

    public function testResolveDerivesCidFromBareValueAndFromNestedPath(): void
    {
        $recording = $this->recording([
            new Response(200, [], $this->resolutionResponse(value: 'QmBare')),
            new Response(200, [], $this->resolutionResponse(value: '/ipfs/QmNested/child.txt')),
        ]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        self::assertSame('QmBare', $client->resolve('a')->cid());
        self::assertSame('QmNested', $client->resolve('b')->cid());
    }

    public function testResolveUrlEncodesTheNameInPath(): void
    {
        // A name is a base58 identifier, but the path segment is still encoded
        // the same defensive way every other id path is (rawurlencode).
        $recording = $this->recording([new Response(200, [], $this->resolutionResponse())]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->resolve('na/me');

        self::assertSame(self::BASE_URL . '/api/ipns/resolve/na%2Fme', (string) $recording->lastRequest()->getUri());
    }

    public function testResolveSendsExactlyOneRequest(): void
    {
        $recording = $this->recording([new Response(200, [], $this->resolutionResponse())]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->resolve('k51qzi5uqu5djg');

        self::assertCount(1, $recording->requests());
    }

    public function testResolveNon2xxThrowsUnexpectedStatusWithoutSecret(): void
    {
        // An unpublished IPNS name is exactly the failure the awaiting match
        // treats as no-match (the name cannot be resolved to a serving CID).
        $recording = $this->recording([
            new Response(404, [], '{"error":"not found s3cret-portal-key-b7f1"}'),
        ]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->resolve('k51qzi5uqu5djg');
            self::fail('Expected UnexpectedStatusCodeException.');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(404, $e->status());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    public function testResolveMissingRequiredFieldThrowsResponseDecoding(): void
    {
        $recording = $this->recording([
            new Response(200, [], '{"name":"i","value":"/ipfs/QmZ","path":"/ipfs/QmZ","sequence":7,"expired":false}'),
        ]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $this->expectException(ResponseDecodingException::class);
        $this->expectExceptionMessage('IPNS resolution response is missing string field "expires".');

        $client->resolve('k51qzi5uqu5djg');
    }

    public function testResolveTransportErrorIsTypedAndNeverLeaksSecret(): void
    {
        $recording = $this->recording([
            new ConnectException('Connection refused', new Request('GET', self::BASE_URL . '/api/ipns/resolve/k51qzi5uqu5djg')),
        ]);
        $client = new IpfsIpnsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->resolve('k51qzi5uqu5djg');
            self::fail('Expected TransportException.');
        } catch (TransportException $e) {
            self::assertSame('GET', $e->method());
            self::assertSame(self::BASE_URL . '/api/ipns/resolve/k51qzi5uqu5djg', $e->uri());
            self::assertSame(
                'Transport error on GET ' . self::BASE_URL . '/api/ipns/resolve/k51qzi5uqu5djg',
                $e->getMessage(),
            );
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    private function resolutionResponse(string $value = '/ipfs/QmZ'): string
    {
        return '{"name":"k51qzi5uqu5djg","value":"' . $value . '","path":"' . $value . '",'
            . '"sequence":7,"expired":false,"expires":"2026-01-11T00:00:00Z"}';
    }
}
