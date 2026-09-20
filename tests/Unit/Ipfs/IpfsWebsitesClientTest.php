<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Ipfs;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Http\TransportException;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use LumeWeb\Cast\Ipfs\IpfsWebsitesClient;
use LumeWeb\Cast\Ipfs\Website;
use LumeWeb\Cast\Publish\CreateWebsiteRequest;
use LumeWeb\Cast\Tests\Unit\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * IpfsWebsitesClient maps the ipfs-sdk Websites.Create (POST /api/websites,
 * domain optional) and Websites.Update (PUT /api/websites/{id}, target-only)
 * calls on the shared PSR-18 transport, with exact JSON bodies, the bearer key
 * only on the wire, and the typed HttpException family preserved. The update
 * verb is PUT because that is the only update operation the local ipfs-sdk
 * swagger defines on /api/websites/{id} (the generated contract has no PATCH).
 */
final class IpfsWebsitesClientTest extends TestCase
{
    private const BASE_URL = 'https://ipfs.pinner.xyz';
    private const API_KEY = 's3cret-portal-key-55d2';

    /**
     * @param list<ResponseInterface|ClientExceptionInterface> $queue
     */
    private function recording(array $queue): RecordingTransport
    {
        return RecordingTransport::withResponses($queue);
    }

    /**
     * @param list<ResponseInterface|ClientExceptionInterface> $queue
     */
    private function clientBy(array $queue): IpfsWebsitesClient
    {
        $recording = $this->recording($queue);

        return new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function websiteRows(int $count, int $firstId = 1): array
    {
        $rows = [];
        for ($i = 0; $i < $count; ++$i) {
            $id = $firstId + $i;
            $rows[] = [
                'id' => $id,
                'status' => 'active',
                'domain' => 'site-' . $id . '.example.test',
                'target_hash' => 'QmCid' . $id,
                'target_type' => 'ipfs',
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function websiteListResponse(array $rows, int $total): string
    {
        return (string) json_encode(['data' => $rows, 'total' => $total], JSON_THROW_ON_ERROR);
    }

    public function testCreateSendsPostToWebsitesWithExactBodyWithoutDomainAndBearer(): void
    {
        $recording = $this->recording([new Response(201, [], $this->websiteResponse())]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->create(new CreateWebsiteRequest('QmCid', 'car', 'mysite'));

        $request = $recording->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            '{"target_hash":"QmCid","target_type":"car","label":"mysite"}',
            (string) $request->getBody(),
        );
    }

    public function testCreateOmitsDomainKeyEntirelyWhenDomainIsNull(): void
    {
        $recording = $this->recording([new Response(201, [], $this->websiteResponse())]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->create(new CreateWebsiteRequest('QmCid', 'car', 'mysite'));

        $body = (string) $recording->lastRequest()->getBody();
        self::assertStringNotContainsString('domain', $body);
    }

    public function testCreateIncludesDomainWhenProvided(): void
    {
        $recording = $this->recording([new Response(201, [], $this->websiteResponse())]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->create(new CreateWebsiteRequest('QmCid', 'car', 'mysite', 'site.example.test'));

        self::assertSame(
            '{"target_hash":"QmCid","target_type":"car","label":"mysite","domain":"site.example.test"}',
            (string) $recording->lastRequest()->getBody(),
        );
    }

    public function testCreateAutoGenerateSendsGenerateManagedDnsAndNoDomainOrLabel(): void
    {
        // The confirmed empty-hostname (auto-generate) path: generate + managed
        // dns hosting, and NEITHER a domain NOR a label — a make-up label must
        // never ride along (it is what minted site.pinned.site).
        $recording = $this->recording([new Response(201, [], $this->websiteResponse())]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->create(new CreateWebsiteRequest(
            'k51qzi5uqu5djg',
            'ipns',
            label: null,
            domain: null,
            namespace: null,
            generate: true,
            dnsHostingEnabled: true,
        ));

        $body = (string) $recording->lastRequest()->getBody();
        self::assertSame(
            '{"target_hash":"k51qzi5uqu5djg","target_type":"ipns","generate":true,"dns_hosting_enabled":true}',
            $body,
        );
        self::assertStringNotContainsString('domain', $body);
        self::assertStringNotContainsString('label', $body);
        self::assertStringNotContainsString('namespace', $body);
    }

    public function testCreateCustomDomainSendsDomainIcannNamespaceAndManagedDnsWithoutGenerate(): void
    {
        // A named hostname is the pinner CLI custom-domain contract: domain +
        // icann namespace + managed dns hosting, and no generate flag.
        $recording = $this->recording([new Response(201, [], $this->websiteResponse())]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->create(new CreateWebsiteRequest(
            'k51qzi5uqu5djg',
            'ipns',
            'shop.example.com',
            'shop.example.com',
            'icann',
            false,
            true,
        ));

        $body = (string) $recording->lastRequest()->getBody();
        self::assertSame(
            '{"target_hash":"k51qzi5uqu5djg","target_type":"ipns","label":"shop.example.com","domain":"shop.example.com","namespace":"icann","dns_hosting_enabled":true}',
            $body,
        );
        self::assertStringNotContainsString('"generate"', $body);
    }

    public function testCreateNormalizesWebsiteTargetTypeToIpfsOnTheWire(): void
    {
        // RunSettings defaults the internal target type to 'website', but the
        // portal's WebsiteRequest only accepts ipfs|ipns — the request body must
        // carry 'ipfs' while the in-memory value stays untouched.
        $recording = $this->recording([new Response(201, [], $this->websiteResponse())]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->create(new CreateWebsiteRequest('QmCid', 'website', 'mysite'));

        self::assertSame(
            '{"target_hash":"QmCid","target_type":"ipfs","label":"mysite"}',
            (string) $recording->lastRequest()->getBody(),
        );
    }

    public function testCreateLeavesNonWebsiteTargetTypeUnchangedOnTheWire(): void
    {
        $recording = $this->recording([new Response(201, [], $this->websiteResponse())]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->create(new CreateWebsiteRequest('QmCid', 'ipns', 'mysite'));

        self::assertSame(
            '{"target_hash":"QmCid","target_type":"ipns","label":"mysite"}',
            (string) $recording->lastRequest()->getBody(),
        );
    }

    public function testCreateSendsIpnsDefaultThroughWithIpnsNameAsTargetHash(): void
    {
        // Wire contract: a new ipns-default run creates the website
        // with target_type ipns and target_hash = the mutable IPNS name (not
        // the raw CID), both passed through unchanged.
        $recording = $this->recording([new Response(201, [], $this->websiteResponse())]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->create(new CreateWebsiteRequest('k51qzi5uqu5djg', 'ipns', 'mysite'));

        self::assertSame(
            '{"target_hash":"k51qzi5uqu5djg","target_type":"ipns","label":"mysite"}',
            (string) $recording->lastRequest()->getBody(),
        );
    }

    public function testCreateSendsExactlyOneRequestForAFirstPublish(): void
    {
        $recording = $this->recording([new Response(201, [], $this->websiteResponse())]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->create(new CreateWebsiteRequest('QmCid', 'car', 'mysite'));

        self::assertCount(1, $recording->requests());
    }

    public function testCreateMapsIdStatusDomainActiveCidAndIpnsKeyId(): void
    {
        $recording = $this->recording([new Response(201, [], $this->websiteResponse())]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $website = $client->create(new CreateWebsiteRequest('QmCid', 'car', 'mysite'));

        self::assertInstanceOf(Website::class, $website);
        self::assertSame(7, $website->id());
        self::assertSame('pending', $website->status());
        self::assertSame('', $website->domain());
        self::assertSame('QmCid', $website->activeCid());
        self::assertSame(12, $website->ipnsKeyId());
        self::assertSame('QmCid', $website->targetHash());
        self::assertSame('car', $website->targetType());
    }

    public function testCreateAcceptsOkStatusAsSdkDoes(): void
    {
        $recording = $this->recording([new Response(200, [], $this->websiteResponse())]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $website = $client->create(new CreateWebsiteRequest('QmCid', 'car', 'mysite'));

        self::assertSame(7, $website->id());
        self::assertSame('pending', $website->status());
    }

    public function testCreateNon2xxThrowsUnexpectedStatusWithoutSecret(): void
    {
        $recording = $this->recording([
            new Response(422, [], '{"error":"invalid label s3cret-portal-key-55d2"}'),
        ]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->create(new CreateWebsiteRequest('QmCid', 'car', 'mysite'));
            self::fail('Expected UnexpectedStatusCodeException.');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(422, $e->status());
            self::assertStringContainsString('Unexpected HTTP status 422', $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    public function testCreateMalformedJsonThrowsResponseDecoding(): void
    {
        $recording = $this->recording([new Response(201, [], 'not-json{{')]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $this->expectException(ResponseDecodingException::class);

        $client->create(new CreateWebsiteRequest('QmCid', 'car', 'mysite'));
    }

    public function testCreateMissingRequiredFieldThrowsResponseDecoding(): void
    {
        $recording = $this->recording([new Response(201, [], '{"id":7}')]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $this->expectException(ResponseDecodingException::class);
        $this->expectExceptionMessage('Website response is missing string field "status".');

        $client->create(new CreateWebsiteRequest('QmCid', 'car', 'mysite'));
    }

    public function testCreateTransportErrorIsTypedAndNeverLeaksSecret(): void
    {
        $recording = $this->recording([
            new ConnectException('Connection refused', new Request('POST', self::BASE_URL . '/api/websites')),
        ]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->create(new CreateWebsiteRequest('QmCid', 'car', 'mysite'));
            self::fail('Expected TransportException.');
        } catch (TransportException $e) {
            self::assertSame('POST', $e->method());
            self::assertSame(self::BASE_URL . '/api/websites', $e->uri());
            self::assertSame(
                'Transport error on POST ' . self::BASE_URL . '/api/websites',
                $e->getMessage(),
            );
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    public function testUpdateSendsPutToWebsitesIdWithExactBodyAndMapsResponse(): void
    {
        $recording = $this->recording([new Response(200, [], $this->websiteResponse())]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $website = $client->update('7', 'QmNew', 'car');

        $request = $recording->lastRequest();
        self::assertSame('PUT', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/7', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"target_hash":"QmNew","target_type":"car"}', (string) $request->getBody());

        self::assertSame(7, $website->id());
        self::assertSame('pending', $website->status());
        self::assertSame(12, $website->ipnsKeyId());
    }

    public function testUpdateUrlEncodesWebsiteIdInPath(): void
    {
        $recording = $this->recording([new Response(200, [], $this->websiteResponse())]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->update('w/1', 'QmNew', 'car');

        self::assertSame(self::BASE_URL . '/api/websites/w%2F1', (string) $recording->lastRequest()->getUri());
    }

    public function testUpdateNormalizesWebsiteTargetTypeToIpfsOnTheWire(): void
    {
        // The re-point path shares the creative wire contract: an internal
        // 'website' target is serialized as 'ipfs'.
        $recording = $this->recording([new Response(200, [], $this->websiteResponse())]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->update('7', 'QmNew', 'website');

        self::assertSame(
            '{"target_hash":"QmNew","target_type":"ipfs"}',
            (string) $recording->lastRequest()->getBody(),
        );
    }

    public function testUpdateSendsIpnsRepointThroughWithIpnsNameAsTargetHash(): void
    {
        // The ipns-default re-point carries target_type ipns and the mutable
        // IPNS name as target_hash, serialized unchanged.
        $recording = $this->recording([new Response(200, [], $this->websiteResponse())]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->update('7', 'k51qzi5uqu5djg', 'ipns');

        self::assertSame(
            '{"target_hash":"k51qzi5uqu5djg","target_type":"ipns"}',
            (string) $recording->lastRequest()->getBody(),
        );
    }

    public function testUpdateNon2xxThrowsUnexpectedStatusWithoutSecret(): void
    {
        $recording = $this->recording([new Response(404, [], '{"error":"missing"}')]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->update('7', 'QmNew', 'car');
            self::fail('Expected UnexpectedStatusCodeException.');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(404, $e->status());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    public function testUpdateEmptyBodyThrowsResponseDecoding(): void
    {
        $recording = $this->recording([new Response(200, [], '')]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $this->expectException(ResponseDecodingException::class);

        $client->update('7', 'QmNew', 'car');
    }

    public function testGetSendsReadToWebsitesIdAndMapsStatusAndActiveCid(): void
    {
        $recording = $this->recording([new Response(200, [], $this->websiteResponse())]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $website = $client->get('7');

        $request = $recording->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/7', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('', $request->getHeaderLine('Content-Type'));

        self::assertSame(7, $website->id());
        self::assertSame('pending', $website->status());
        self::assertSame('QmCid', $website->activeCid());
        self::assertSame('QmCid', $website->targetHash());
        self::assertSame('car', $website->targetType());
    }

    public function testGetUrlEncodesWebsiteIdInPath(): void
    {
        $recording = $this->recording([new Response(200, [], $this->websiteResponse())]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->get('w/1');

        self::assertSame(self::BASE_URL . '/api/websites/w%2F1', (string) $recording->lastRequest()->getUri());
    }

    public function testGetNon2xxThrowsUnexpectedStatusWithoutSecret(): void
    {
        $recording = $this->recording([new Response(404, [], '{"error":"missing s3cret-portal-key-55d2"}')]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->get('7');
            self::fail('Expected UnexpectedStatusCodeException.');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(404, $e->status());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    /* ------------------------------- list ------------------------------- */

    public function testListSendsGetToWebsitesWithWindowQueryAndReadHeaders(): void
    {
        $recording = $this->recording([new Response(200, [], '{"data":[],"total":0}')]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->list();

        $request = $recording->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites?_start=0&_end=100', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        // A read call carries no Content-Type, matching the read convention.
        self::assertSame('', $request->getHeaderLine('Content-Type'));
    }

    public function testListMapsWebsiteRowsIncludingTargetHash(): void
    {
        $client = $this->clientBy([new Response(200, [], $this->websiteListResponse([
            ['id' => 7, 'status' => 'active', 'domain' => 'blog.example.test', 'target_hash' => 'QmCid', 'target_type' => 'car'],
        ], 1))]);

        $websites = $client->list();

        self::assertCount(1, $websites);
        $website = $websites[0];
        self::assertInstanceOf(Website::class, $website);
        self::assertSame(7, $website->id());
        self::assertSame('active', $website->status());
        self::assertSame('blog.example.test', $website->domain());
        // list() exposes the website target_hash so the awaiting-website
        // derivation can match it against the workspace's preserved CID.
        self::assertSame('QmCid', $website->targetHash());
        self::assertSame('car', $website->targetType());
    }

    public function testListPaginatesUntilAShortPage(): void
    {
        $rows = $this->websiteRows(100);
        $recording = $this->recording([
            new Response(200, [], $this->websiteListResponse($rows, 150)),
            new Response(200, [], $this->websiteListResponse($this->websiteRows(50, 101), 150)),
        ]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $websites = $client->list();

        self::assertCount(150, $websites);
        self::assertSame(1, $websites[0]->id());
        self::assertSame(150, $websites[149]->id());

        // Two pages were walked: the second window starts where the first ended.
        $requests = $recording->requests();
        self::assertCount(2, $requests);
        self::assertStringContainsString('_start=0&_end=100', (string) $requests[0]->getUri());
        self::assertStringContainsString('_start=100&_end=200', (string) $requests[1]->getUri());
    }

    public function testListStopsAtDeclaredTotalWhenTheWindowIsStillFull(): void
    {
        // A full first page whose total says everything is already collected:
        // the window is not re-requested (belt-and-braces bound).
        $recording = $this->recording([
            new Response(200, [], $this->websiteListResponse($this->websiteRows(100), 100)),
        ]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $websites = $client->list();

        self::assertCount(100, $websites);
        self::assertCount(1, $recording->requests());
    }

    public function testListReturnsEmptyArrayForNoWebsites(): void
    {
        $client = $this->clientBy([new Response(200, [], '{"data":[],"total":0}')]);

        self::assertSame([], $client->list());
    }

    public function testListMissingDataArrayThrowsResponseDecodingException(): void
    {
        $client = $this->clientBy([new Response(200, [], '{"total":1}')]);

        $this->expectException(ResponseDecodingException::class);
        $this->expectExceptionMessage('Website list response is missing the "data" array.');
        $client->list();
    }

    public function testListNonJsonBodyThrowsResponseDecodingException(): void
    {
        $client = $this->clientBy([new Response(200, [], 'not json')]);

        $this->expectException(ResponseDecodingException::class);
        $client->list();
    }

    public function testListRowMissingRequiredFieldThrowsResponseDecodingException(): void
    {
        $client = $this->clientBy([new Response(200, [], $this->websiteListResponse([
            ['id' => 1, 'status' => 'active', 'domain' => 'a.test', 'target_type' => 'car'],
        ], 1))]);

        $this->expectException(ResponseDecodingException::class);
        $client->list();
    }

    public function testListNon2xxThrowsUnexpectedStatusWithoutSecret(): void
    {
        $recording = $this->recording([new Response(401, [], '{"error":"unauthorized s3cret-portal-key-55d2"}')]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->list();
            self::fail('Expected UnexpectedStatusCodeException.');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(401, $e->status());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    public function testValidateSendsPostToWebsitesIdValidateWithNoBodyAndBearer(): void
    {
        $recording = $this->recording([new Response(200, [], $this->websiteValidateResponse())]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $validation = $client->validate('42');

        $request = $recording->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/42/validate', (string) $request->getUri());
        self::assertSame('', (string) $request->getBody());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertInstanceOf(\LumeWeb\Cast\Ipfs\WebsiteValidation::class, $validation);
        self::assertSame(42, $validation->id());
        self::assertSame('site.example.test', $validation->domain());
        self::assertFalse($validation->valid());
        self::assertSame('DNS records not yet published.', $validation->message());
        self::assertSame('dns_validation_failed', $validation->reason());
        self::assertCount(1, $validation->checks());
        self::assertSame('dnslink', $validation->checks()[0]->name());
        self::assertFalse($validation->checks()[0]->ok());
        self::assertSame('dnslink=/ipns/k-ipns-7', $validation->checks()[0]->expected());
        self::assertSame('', $validation->checks()[0]->found());
    }

    public function testValidateUrlEncodesWebsiteIdInPath(): void
    {
        $recording = $this->recording([new Response(200, [], $this->websiteValidateResponse())]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->validate('w/1');

        self::assertSame(self::BASE_URL . '/api/websites/w%2F1/validate', (string) $recording->lastRequest()->getUri());
    }

    public function testValidateNon2xxThrowsUnexpectedStatusWithoutSecret(): void
    {
        $recording = $this->recording([
            new Response(422, [], '{"error":"validation failed s3cret-portal-key-55d2"}'),
        ]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->validate('42');
            self::fail('Expected UnexpectedStatusCodeException.');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(422, $e->status());
            self::assertStringContainsString('Unexpected HTTP status 422', $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    public function testValidateMissingRequiredFieldThrowsResponseDecoding(): void
    {
        $recording = $this->recording([new Response(200, [], '{"id":42}')]);
        $client = new IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY);

        // The valid flag is the first truly required field: id parses, domain
        // and message/reason are stringy-and-optional, so the decode reports
        // the missing boolean.
        $this->expectException(ResponseDecodingException::class);
        $this->expectExceptionMessage('Website validation response is missing boolean field "valid".');

        $client->validate('42');
    }

    private function websiteResponse(): string
    {
        return '{"id":7,"status":"pending","domain":"","active_cid":"QmCid","ipns_key_id":12,'
            . '"target_hash":"QmCid","target_type":"car","created":"2026-01-02T00:00:00Z",'
            . '"updated":"2026-01-02T00:00:00Z","expired":false,"dns_hosting_enabled":false,'
            . '"is_subdomain":false,"validation_token":"tok"}';
    }

    private function websiteValidateResponse(): string
    {
        return '{"id":42,"domain":"site.example.test","valid":false,"message":"DNS records not yet published.",'
            . '"reason":"dns_validation_failed","checks":[{"name":"dnslink","ok":false,"message":"",'
            . '"expected":"dnslink=/ipns/k-ipns-7","found":""}]}';
    }
}
