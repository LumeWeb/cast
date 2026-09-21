<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Http\TransportException;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use LumeWeb\Cast\Ipfs\IpfsDomainsClient;
use LumeWeb\Cast\Ipfs\PlatformAvailability;
use LumeWeb\Cast\Ipfs\PlatformDomain;
use LumeWeb\Cast\Ipfs\SslStatusInfo;
use LumeWeb\Cast\Publish\Domain;
use LumeWeb\Cast\Publish\DomainClientException;
use LumeWeb\Cast\Publish\IpfsDomainClient;
use LumeWeb\Cast\Tests\Unit\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * IpfsDomainClient adapts the ipfs-sdk domains client onto the Publish domain
 * boundary: binding a domain delegates to IpfsDomainsClient::addDomain(), while
 * list/DNS/verify/delete delegate to the matching SDK calls, and the returned
 * WebsiteDomain is mapped onto the Publish Domain value — the numeric binding
 * id becomes the string identity the registry persists, while domain, namespace
 * and lifecycle status pass through unchanged. The read-only platform and SSL
 * helpers return the ipfs-sdk DTOs unchanged. Every mapping is driven over a
 * RecordingTransport so no network is ever touched, and the bearer API key —
 * which only lives on the wire — is asserted to never appear in any adapter
 * exception message.
 */
final class IpfsDomainClientTest extends TestCase
{
    private const BASE_URL = 'https://ipfs.pinner.xyz';
    private const API_KEY = 's3cret-portal-key-55d2';

    /**
     * Builds the adapter on a recording transport, returning [recording, adapter].
     *
     * @param list<ResponseInterface|ClientExceptionInterface> $queue
     *
     * @return array{0: RecordingTransport, 1: IpfsDomainClient}
     */
    private function build(array $queue): array
    {
        $recording = RecordingTransport::withResponses($queue);
        $domains = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        return [$recording, new IpfsDomainClient($domains)];
    }

    public function testBindDelegatesToDomainsClientAndMapsWebsiteDomainToPublishDomain(): void
    {
        [$recording, $adapter] = $this->build([new Response(201, [], $this->domainResponse())]);

        $domain = $adapter->bind('42', 'site.example.test', 'icann');

        self::assertInstanceOf(Domain::class, $domain);
        self::assertSame('99', $domain->id);
        self::assertSame('site.example.test', $domain->domain);
        self::assertSame('icann', $domain->namespace);
        self::assertFalse($domain->dnsHostingEnabled);
        self::assertSame('pending', $domain->status);
        self::assertNull($domain->gatewayHost);

        $request = $recording->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/42/domains', (string) $request->getUri());
        self::assertSame('{"domain":"site.example.test","namespace":"icann"}', (string) $request->getBody());
        self::assertCount(1, $recording->requests());
    }

    public function testBindMapsDnsHostingAndGatewayHostWhenPresent(): void
    {
        [$recording, $adapter] = $this->build([
            new Response(201, [], $this->domainResponse(7, 'name/', 'hns', true, 'waiting_delegation', 'gw.example.com')),
        ]);

        $domain = $adapter->bind('42', 'name/', 'hns');

        self::assertSame('7', $domain->id);
        self::assertSame('name/', $domain->domain);
        self::assertSame('hns', $domain->namespace);
        self::assertTrue($domain->dnsHostingEnabled);
        self::assertSame('waiting_delegation', $domain->status);
        self::assertSame('gw.example.com', $domain->gatewayHost);
    }

    public function testBindRejectsUnsupportedNamespaceWithoutNetwork(): void
    {
        [$recording, $adapter] = $this->build([]);

        try {
            $adapter->bind('42', 'site.example.test', 'dnssec');
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Unsupported domain namespace "dnssec"', $e->getMessage());
        }

        self::assertCount(0, $recording->requests());
    }

    public function testBindNon2xxMapsToDomainClientExceptionWithoutSecret(): void
    {
        [$recording, $adapter] = $this->build([
            new Response(422, [], '{"error":"bind failed s3cret-portal-key-55d2"}'),
        ]);

        try {
            $adapter->bind('42', 'site.example.test', 'icann');
            self::fail('Expected DomainClientException.');
        } catch (DomainClientException $e) {
            self::assertGreaterThan(0, strlen((string) $e));
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(UnexpectedStatusCodeException::class, $e->getPrevious());
            self::assertSame(422, $e->getPrevious()->status());
        }

        self::assertCount(1, $recording->requests());
    }

    public function testBindMalformedJsonMapsToDomainClientExceptionWithDecodingPrevious(): void
    {
        [$recording, $adapter] = $this->build([new Response(201, [], 'not-json{{')]);

        try {
            $adapter->bind('42', 'site.example.test', 'icann');
            self::fail('Expected DomainClientException.');
        } catch (DomainClientException $e) {
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(ResponseDecodingException::class, $e->getPrevious());
        }

        self::assertCount(1, $recording->requests());
    }

    public function testBindTransportErrorMapsToDomainClientExceptionWithoutSecret(): void
    {
        $recording = RecordingTransport::withResponses([
            new ConnectException('Connection refused', new Request('POST', self::BASE_URL . '/api/websites/42/domains')),
        ]);
        $adapter = new IpfsDomainClient(
            new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY),
        );

        try {
            $adapter->bind('42', 'site.example.test', 'icann');
            self::fail('Expected DomainClientException.');
        } catch (DomainClientException $e) {
            self::assertStringContainsString('Transport error on POST', $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(TransportException::class, $e->getPrevious());
        }
    }

    public function testListDelegatesToDomainsClientAndMapsEachDomain(): void
    {
        [$recording, $adapter] = $this->build([new Response(200, [], $this->domainListResponse())]);

        $domains = $adapter->list('42');

        $request = $recording->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/42/domains', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('', $request->getHeaderLine('Content-Type'));

        self::assertCount(2, $domains);
        self::assertContainsOnlyInstancesOf(Domain::class, $domains);
        self::assertSame('99', $domains[0]->id);
        self::assertSame('site.example.test', $domains[0]->domain);
        self::assertSame('icann', $domains[0]->namespace);
        self::assertSame('7', $domains[1]->id);
        self::assertSame('name/', $domains[1]->domain);
        self::assertSame('hns', $domains[1]->namespace);
        self::assertTrue($domains[1]->dnsHostingEnabled);
        self::assertSame('gw.example.com', $domains[1]->gatewayHost);
    }

    public function testListNon2xxMapsToDomainClientExceptionWithoutSecret(): void
    {
        [$recording, $adapter] = $this->build([
            new Response(404, [], '{"error":"missing s3cret-portal-key-55d2"}'),
        ]);

        try {
            $adapter->list('42');
            self::fail('Expected DomainClientException.');
        } catch (DomainClientException $e) {
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(UnexpectedStatusCodeException::class, $e->getPrevious());
            self::assertSame(404, $e->getPrevious()->status());
        }

        self::assertCount(1, $recording->requests());
    }

    public function testDnsRequirementsDelegatesAndMapsDomain(): void
    {
        [$recording, $adapter] = $this->build([
            new Response(200, [], $this->domainResponse(9, 'name/', 'hns', true, 'waiting_delegation', 'gw.example.com')),
        ]);

        $domain = $adapter->dnsRequirements('42', '9');

        $request = $recording->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/42/domains/9/dns-requirements', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));

        self::assertInstanceOf(Domain::class, $domain);
        self::assertSame('9', $domain->id);
        self::assertSame('name/', $domain->domain);
        self::assertSame('hns', $domain->namespace);
        self::assertSame('waiting_delegation', $domain->status);
    }

    public function testDnsRequirementsNon2xxMapsToDomainClientExceptionWithoutSecret(): void
    {
        [$recording, $adapter] = $this->build([
            new Response(422, [], '{"error":"INVALID_REQUEST s3cret-portal-key-55d2"}'),
        ]);

        try {
            $adapter->dnsRequirements('42', '9');
            self::fail('Expected DomainClientException.');
        } catch (DomainClientException $e) {
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(UnexpectedStatusCodeException::class, $e->getPrevious());
            self::assertSame(422, $e->getPrevious()->status());
        }

        self::assertCount(1, $recording->requests());
    }

    public function testVerifyDelegatesAndMapsStatus(): void
    {
        [$recording, $adapter] = $this->build([
            new Response(200, [], $this->domainResponse(9, 'name/', 'hns', true, 'active')),
        ]);

        $domain = $adapter->verify('42', '9');

        $request = $recording->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/42/domains/9/verify', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('', (string) $request->getBody());

        self::assertInstanceOf(Domain::class, $domain);
        self::assertSame('9', $domain->id);
        self::assertSame('active', $domain->status);
    }

    public function testVerifyNon2xxMapsToDomainClientExceptionWithoutSecret(): void
    {
        [$recording, $adapter] = $this->build([
            new Response(422, [], '{"error":"DNS_VALIDATION_FAILED s3cret-portal-key-55d2"}'),
        ]);

        try {
            $adapter->verify('42', '9');
            self::fail('Expected DomainClientException.');
        } catch (DomainClientException $e) {
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(UnexpectedStatusCodeException::class, $e->getPrevious());
            self::assertSame(422, $e->getPrevious()->status());
        }

        self::assertCount(1, $recording->requests());
    }

    public function testValidateDelegatesToWebsitesClientAndReturnsValidation(): void
    {
        [$recording, $adapter] = $this->buildWithWebsites([new Response(200, [], $this->websiteValidateResponse())]);

        $validation = $adapter->validate('42');

        $request = $recording->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/42/validate', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('', (string) $request->getBody());

        self::assertInstanceOf(\LumeWeb\Cast\Ipfs\WebsiteValidation::class, $validation);
        self::assertSame(42, $validation->id());
        self::assertFalse($validation->valid());
        self::assertSame('dns_validation_failed', $validation->reason());
        self::assertCount(1, $validation->checks());
        self::assertSame('dnslink', $validation->checks()[0]->name());
        self::assertSame('dnslink=/ipns/k-ipns-7', $validation->checks()[0]->expected());
    }

    public function testValidateRefusesWithoutAWebsitesClientWithoutNetwork(): void
    {
        [$recording, $adapter] = $this->build([]);

        try {
            $adapter->validate('42');
            self::fail('Expected DomainClientException.');
        } catch (DomainClientException $e) {
            self::assertStringContainsString('not available', $e->getMessage());
        }

        self::assertCount(0, $recording->requests());
    }

    public function testValidateNon2xxMapsToDomainClientExceptionWithoutSecret(): void
    {
        [$recording, $adapter] = $this->buildWithWebsites([
            new Response(422, [], '{"error":"validation failed s3cret-portal-key-55d2"}'),
        ]);

        try {
            $adapter->validate('42');
            self::fail('Expected DomainClientException.');
        } catch (DomainClientException $e) {
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(UnexpectedStatusCodeException::class, $e->getPrevious());
            self::assertSame(422, $e->getPrevious()->status());
        }

        self::assertCount(1, $recording->requests());
    }

    public function testDeleteDelegatesToDomainsClientAndAccepts204(): void
    {
        [$recording, $adapter] = $this->build([new Response(204, [], '')]);

        $adapter->delete('42', '7');

        $request = $recording->lastRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/42/domains/7', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertCount(1, $recording->requests());
    }

    public function testDeleteNon2xxMapsToDomainClientExceptionWithoutSecret(): void
    {
        [$recording, $adapter] = $this->build([
            new Response(404, [], '{"error":"missing s3cret-portal-key-55d2"}'),
        ]);

        try {
            $adapter->delete('42', '7');
            self::fail('Expected DomainClientException.');
        } catch (DomainClientException $e) {
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(UnexpectedStatusCodeException::class, $e->getPrevious());
            self::assertSame(404, $e->getPrevious()->status());
        }

        self::assertCount(1, $recording->requests());
    }

    public function testDeleteTransportErrorMapsToDomainClientExceptionWithoutSecret(): void
    {
        $recording = RecordingTransport::withResponses([
            new ConnectException('Connection refused', new Request('DELETE', self::BASE_URL . '/api/websites/42/domains/7')),
        ]);
        $adapter = new IpfsDomainClient(
            new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY),
        );

        try {
            $adapter->delete('42', '7');
            self::fail('Expected DomainClientException.');
        } catch (DomainClientException $e) {
            self::assertStringContainsString('Transport error on DELETE', $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(TransportException::class, $e->getPrevious());
        }
    }

    public function testListPlatformDomainsDelegatesAndMapsEnvelope(): void
    {
        [$recording, $adapter] = $this->build([new Response(200, [], $this->platformDomainListResponse())]);

        $platforms = $adapter->listPlatformDomains();

        $request = $recording->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/platform-domains', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));

        self::assertCount(2, $platforms);
        self::assertContainsOnlyInstancesOf(PlatformDomain::class, $platforms);
        self::assertSame('pinner.xyz', $platforms[0]->domain());
        self::assertSame('icann', $platforms[0]->namespace());
        self::assertTrue($platforms[0]->enabled());
        self::assertFalse($platforms[1]->enabled());
    }

    public function testCheckPlatformDomainAvailabilityDelegatesAndMapsResults(): void
    {
        [$recording, $adapter] = $this->build([new Response(200, [], $this->platformAvailabilityResponse())]);

        $result = $adapter->checkPlatformDomainAvailability('my-site');

        $request = $recording->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(
            self::BASE_URL . '/api/websites/platform-domains/availability?label=my-site',
            (string) $request->getUri(),
        );
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));

        self::assertInstanceOf(PlatformAvailability::class, $result);
        self::assertSame('my-site', $result->label());
        self::assertCount(2, $result->results());
        self::assertSame('pinner.xyz', $result->results()[0]->platformDomain());
        self::assertTrue($result->results()[0]->available());
        self::assertFalse($result->results()[1]->available());
    }

    public function testListPlatformDomainsNon2xxMapsToDomainClientExceptionWithoutSecret(): void
    {
        [$recording, $adapter] = $this->build([
            new Response(500, [], '{"error":"boom s3cret-portal-key-55d2"}'),
        ]);

        try {
            $adapter->listPlatformDomains();
            self::fail('Expected DomainClientException.');
        } catch (DomainClientException $e) {
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(UnexpectedStatusCodeException::class, $e->getPrevious());
            self::assertSame(500, $e->getPrevious()->status());
        }

        self::assertCount(1, $recording->requests());
    }

    public function testSslStatusDelegatesAndMapsInfo(): void
    {
        [$recording, $adapter] = $this->build([new Response(200, [], $this->sslStatusResponse())]);

        $ssl = $adapter->sslStatus('example.com');

        $request = $recording->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/example.com/ssl-status', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));

        self::assertInstanceOf(SslStatusInfo::class, $ssl);
        self::assertSame('ready', $ssl->status());
        self::assertSame('2026-01-02T00:00:00Z', $ssl->issuedAt());
        self::assertNull($ssl->error());
    }

    public function testSslStatusReturnsNullWhenSslAbsent(): void
    {
        [$recording, $adapter] = $this->build([new Response(200, [], $this->sslAbsentResponse())]);

        self::assertNull($adapter->sslStatus('example.com'));
        self::assertCount(1, $recording->requests());
    }

    public function testSslStatusNon2xxMapsToDomainClientExceptionWithoutSecret(): void
    {
        [$recording, $adapter] = $this->build([
            new Response(404, [], '{"error":"missing s3cret-portal-key-55d2"}'),
        ]);

        try {
            $adapter->sslStatus('example.com');
            self::fail('Expected DomainClientException.');
        } catch (DomainClientException $e) {
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
            self::assertInstanceOf(UnexpectedStatusCodeException::class, $e->getPrevious());
            self::assertSame(404, $e->getPrevious()->status());
        }

        self::assertCount(1, $recording->requests());
    }

    /**
     * Builds the adapter on a recording transport with BOTH the domains client
     * and the raw websites client wired, so website-level DNS validation
     * (POST /api/websites/{id}/validate) can be exercised end to end.
     *
     * @param list<ResponseInterface|ClientExceptionInterface> $queue
     *
     * @return array{0: RecordingTransport, 1: IpfsDomainClient}
     */
    private function buildWithWebsites(array $queue): array
    {
        $recording = RecordingTransport::withResponses($queue);

        return [
            $recording,
            new IpfsDomainClient(
                new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY),
                new \LumeWeb\Cast\Ipfs\IpfsWebsitesClient($recording->transport(), self::BASE_URL, self::API_KEY),
            ),
        ];
    }

    private function domainListResponse(): string
    {
        return '{"data":[' . $this->domainResponse() . ',' . $this->domainResponse(
            7,
            'name/',
            'hns',
            true,
            'waiting_delegation',
            'gw.example.com',
        ) . '],"total":2}';
    }

    private function domainResponse(
        int $id = 99,
        string $domain = 'site.example.test',
        string $namespace = 'icann',
        bool $dnsHostingEnabled = false,
        string $status = 'pending',
        ?string $gatewayHost = null,
    ): string {
        $gateway = $gatewayHost === null ? 'null' : '"' . $gatewayHost . '"';

        return '{"id":' . $id . ',"domain":"' . $domain . '","namespace":"' . $namespace . '",'
            . '"dns_hosting_enabled":' . ($dnsHostingEnabled ? 'true' : 'false') . ',"status":"' . $status . '",'
            . '"gateway_host":' . $gateway . '}';
    }

    private function websiteValidateResponse(): string
    {
        return '{"id":42,"domain":"site.example.test","valid":false,"message":"DNS records not yet published.",'
            . '"reason":"dns_validation_failed","checks":[{"name":"dnslink","ok":false,"message":"",'
            . '"expected":"dnslink=/ipns/k-ipns-7","found":""}]}';
    }

    private function platformDomainListResponse(): string
    {
        return '{"data":[{"id":3,"domain":"pinner.xyz","namespace":"icann","zone_id":101,"enabled":true},'
            . '{"id":4,"domain":"pinner.test","namespace":"icann","zone_id":102,"enabled":false}],"total":2}';
    }

    private function platformAvailabilityResponse(): string
    {
        return '{"label":"my-site","results":[{"platform_domain":"pinner.xyz","namespace":"icann","available":true},'
            . '{"platform_domain":"pinner.test","namespace":"icann","available":false}]}';
    }

    private function sslStatusResponse(): string
    {
        return '{"id":7,"domain":"example.com","status":"active","target_type":"car","target_hash":"QmCid",'
            . '"validation_token":"tok","dns_hosting_enabled":false,"is_subdomain":false,'
            . '"created":"2026-01-02T00:00:00Z","updated":"2026-01-02T00:00:00Z","expired":false,'
            . '"ssl":{"status":"ready","issued_at":"2026-01-02T00:00:00Z",'
            . '"last_updated_at":"2026-01-02T00:00:00Z"}}';
    }

    private function sslAbsentResponse(): string
    {
        return '{"id":7,"domain":"example.com","status":"active","target_type":"car","target_hash":"QmCid",'
            . '"validation_token":"tok","dns_hosting_enabled":false,"is_subdomain":false,'
            . '"created":"2026-01-02T00:00:00Z","updated":"2026-01-02T00:00:00Z","expired":false}';
    }
}
