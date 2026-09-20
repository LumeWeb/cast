<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Http\TransportException;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use LumeWeb\Cast\Ipfs\IpfsWebsitesClient;
use LumeWeb\Cast\Publish\CreateWebsiteRequest;
use LumeWeb\Cast\Publish\IpfsWebsiteClient;
use LumeWeb\Cast\Publish\Website;
use LumeWeb\Cast\Publish\WebsiteClientException;
use LumeWeb\Cast\Tests\Unit\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * IpfsWebsiteClient adapts the ipfs-sdk websites client onto the Publish
 * WebsiteClient boundary: create/update delegate to the IpfsWebsitesClient
 * unchanged, the ipfs Website response is mapped onto the Publish Website value
 * (id becomes the string identity the registry persists, the label rides along
 * from the create request, an empty domain maps to null so a first publish stays
 * "no domain yet"), and the typed HttpException family is translated into a
 * WebsiteClientException whose message is always secret-safe — the API key only
 * ever lives on the wire and is asserted to never appear in adapter exceptions.
 */
final class IpfsWebsiteClientTest extends TestCase
{
    private const BASE_URL = 'https://ipfs.pinner.xyz';
    private const API_KEY = 's3cret-portal-key-55d2';

    /**
     * @param list<ResponseInterface|ClientExceptionInterface> $queue
     */
    private function adapter(array $queue): IpfsWebsiteClient
    {
        $recording = RecordingTransport::withResponses($queue);
        $websites = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        return new IpfsWebsiteClient($websites);
    }

    public function testCreateDelegatesToWebsitesClientAndMapsIdLabelTargetAndNullDomain(): void
    {
        $adapter = $this->adapter([new Response(201, [], $this->websiteResponse(''))]);

        $website = $adapter->create(new CreateWebsiteRequest('QmCid', 'car', 'mysite'));

        self::assertInstanceOf(Website::class, $website);
        self::assertSame('7', $website->id);
        self::assertSame('mysite', $website->label);
        self::assertSame('QmCid', $website->targetHash);
        self::assertSame('car', $website->targetType);
        self::assertNull($website->domain);
    }

    public function testCreateMapsBoundDomainWhenPresent(): void
    {
        $adapter = $this->adapter([new Response(201, [], $this->websiteResponse('site.example.test'))]);

        $website = $adapter->create(new CreateWebsiteRequest('QmCid', 'car', 'mysite'));

        self::assertSame('site.example.test', $website->domain);
    }

    public function testUpdateDelegatesToWebsitesClientAndMapsResponseWithEmptyLabel(): void
    {
        $adapter = $this->adapter([new Response(200, [], $this->websiteResponse(''))]);

        $website = $adapter->update('7', 'QmNew', 'car');

        self::assertInstanceOf(Website::class, $website);
        self::assertSame('7', $website->id);
        self::assertSame('', $website->label);
        self::assertSame('QmCid', $website->targetHash);
        self::assertSame('car', $website->targetType);
        self::assertNull($website->domain);
    }

    public function testCreateNon2xxMapsToWebsiteClientExceptionWithoutSecret(): void
    {
        $adapter = $this->adapter([
            new Response(422, [], '{"error":"invalid label s3cret-portal-key-55d2"}'),
        ]);

        try {
            $adapter->create(new CreateWebsiteRequest('QmCid', 'car', 'mysite'));
            self::fail('Expected WebsiteClientException.');
        } catch (WebsiteClientException $e) {
            self::assertGreaterThan(0, strlen((string) $e));
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(UnexpectedStatusCodeException::class, $e->getPrevious());
            self::assertSame(422, $e->getPrevious()->status());
        }
    }

    public function testUpdateNon2xxMapsToWebsiteClientExceptionWithoutSecret(): void
    {
        $adapter = $this->adapter([new Response(404, [], '{"error":"missing"}')]);

        try {
            $adapter->update('7', 'QmNew', 'car');
            self::fail('Expected WebsiteClientException.');
        } catch (WebsiteClientException $e) {
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(UnexpectedStatusCodeException::class, $e->getPrevious());
        }
    }

    public function testGetDelegatesToWebsitesClientAndMapsStatusAndActiveCid(): void
    {
        $adapter = $this->adapter([new Response(200, [], $this->websiteResponse(''))]);

        $website = $adapter->get('7');

        self::assertInstanceOf(Website::class, $website);
        self::assertSame('7', $website->id);
        self::assertSame('', $website->label);
        self::assertSame('pending', $website->status);
        self::assertSame('QmCid', $website->activeCid);
        self::assertNull($website->domain);
    }

    public function testGetMapsBoundDomainWhenPresent(): void
    {
        $adapter = $this->adapter([new Response(200, [], $this->websiteResponse('site.example.test'))]);

        $website = $adapter->get('7');

        self::assertSame('site.example.test', $website->domain);
    }

    public function testGetNon2xxMapsToWebsiteClientExceptionWithoutSecret(): void
    {
        $adapter = $this->adapter([new Response(404, [], '{"error":"missing s3cret-portal-key-55d2"}')]);

        try {
            $adapter->get('7');
            self::fail('Expected WebsiteClientException.');
        } catch (WebsiteClientException $e) {
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(UnexpectedStatusCodeException::class, $e->getPrevious());
        }
    }

    public function testCreateMalformedJsonMapsToWebsiteClientExceptionWithDecodingPrevious(): void
    {
        $adapter = $this->adapter([new Response(201, [], 'not-json{{')]);

        try {
            $adapter->create(new CreateWebsiteRequest('QmCid', 'car', 'mysite'));
            self::fail('Expected WebsiteClientException.');
        } catch (WebsiteClientException $e) {
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(ResponseDecodingException::class, $e->getPrevious());
        }
    }

    public function testCreateTransportErrorMapsToWebsiteClientExceptionWithoutSecret(): void
    {
        $recording = RecordingTransport::withResponses([
            new ConnectException('Connection refused', new Request('POST', self::BASE_URL . '/api/websites')),
        ]);
        $adapter = new IpfsWebsiteClient(
            new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY),
        );

        try {
            $adapter->create(new CreateWebsiteRequest('QmCid', 'car', 'mysite'));
            self::fail('Expected WebsiteClientException.');
        } catch (WebsiteClientException $e) {
            self::assertStringContainsString('Transport error on POST', $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(TransportException::class, $e->getPrevious());
        }
    }

    private function websiteResponse(string $domain): string
    {
        return '{"id":7,"status":"pending","domain":"' . $domain . '","active_cid":"QmCid","ipns_key_id":12,'
            . '"target_hash":"QmCid","target_type":"car","created":"2026-01-02T00:00:00Z",'
            . '"updated":"2026-01-02T00:00:00Z","expired":false,"dns_hosting_enabled":false,'
            . '"is_subdomain":false,"validation_token":"tok"}';
    }
}
