<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Ipfs;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Http\TransportException;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use LumeWeb\Cast\Ipfs\DaneRepublish;
use LumeWeb\Cast\Ipfs\DomainUpdateRequest;
use LumeWeb\Cast\Ipfs\IpfsDomainsClient;
use LumeWeb\Cast\Ipfs\PlatformAvailability;
use LumeWeb\Cast\Ipfs\PlatformDomain;
use LumeWeb\Cast\Ipfs\SslStatusInfo;
use LumeWeb\Cast\Ipfs\WebsiteDomain;
use LumeWeb\Cast\Ipfs\WebsiteDomainRequest;
use LumeWeb\Cast\Tests\Unit\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * IpfsDomainsClient maps the ipfs-sdk WebsitesService domain-binding endpoints
 * (ListDomains, BindDomain, UpdateDomain, UnbindDomain, VerifyDomain,
 * GetDomainDNSRequirements, RepublishDANE, ConvertDomainToOnChain,
 * ListPlatformDomains, CheckPlatformDomainAvailability, GetSSLStatus) on the
 * shared PSR-18 transport with exact method/path/query/body, the bearer key
 * only on the wire, and the typed HttpException family preserved. Both ICANN
 * and HNS namespaces are exercised, plus the portal-managed/self-managed and
 * one-click platform-domain request variants.
 */
final class IpfsDomainsClientTest extends TestCase
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

    public function testListDomainsSendsGetToExactPathWithBearerAndMapsEnvelope(): void
    {
        $recording = $this->recording([new Response(200, [], $this->domainListResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $domains = $client->listDomains('42');

        $request = $recording->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/42/domains', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('', $request->getHeaderLine('Content-Type'));

        self::assertCount(1, $domains);
        $domain = $domains[0];
        self::assertInstanceOf(WebsiteDomain::class, $domain);
        self::assertSame(7, $domain->id());
        self::assertSame('example.com', $domain->domain());
        self::assertSame('icann', $domain->namespace());
        self::assertTrue($domain->dnsHostingEnabled());
        self::assertSame('active', $domain->status());
        self::assertSame('gw.example.com', $domain->gatewayHost());
        self::assertNull($domain->ownerName());
    }

    public function testListDomainsUrlEncodesWebsiteIdInPath(): void
    {
        $recording = $this->recording([new Response(200, [], $this->domainListResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->listDomains('w/1');

        self::assertSame(self::BASE_URL . '/api/websites/w%2F1/domains', (string) $recording->lastRequest()->getUri());
    }

    public function testListDomainsNon2xxThrowsUnexpectedStatusWithoutSecret(): void
    {
        $recording = $this->recording([new Response(404, [], '{"error":{"reason":"missing","details":"s3cret-portal-key-55d2"}}')]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->listDomains('42');
            self::fail('Expected UnexpectedStatusCodeException.');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(404, $e->status());
            self::assertStringContainsString('Unexpected HTTP status 404', $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    public function testListDomainsMissingDataArrayThrowsResponseDecoding(): void
    {
        $recording = $this->recording([new Response(200, [], '{"total":1}')]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $this->expectException(ResponseDecodingException::class);
        $this->expectExceptionMessage('Domain list response is missing the "data" array.');

        $client->listDomains('42');
    }

    public function testListDomainsMalformedJsonThrowsResponseDecoding(): void
    {
        $recording = $this->recording([new Response(200, [], 'not-json{{')]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $this->expectException(ResponseDecodingException::class);

        $client->listDomains('42');
    }

    public function testListDomainsTransportErrorIsTypedAndNeverLeaksSecret(): void
    {
        $recording = $this->recording([
            new ConnectException('Connection refused', new Request('GET', self::BASE_URL . '/api/websites/42/domains')),
        ]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->listDomains('42');
            self::fail('Expected TransportException.');
        } catch (TransportException $e) {
            self::assertSame('GET', $e->method());
            self::assertSame(self::BASE_URL . '/api/websites/42/domains', $e->uri());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    public function testAddDomainIcannMinimalSendsPostWithExactBody(): void
    {
        $recording = $this->recording([new Response(201, [], $this->domainResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $domain = $client->addDomain('42', WebsiteDomainRequest::icann('example.com'));

        $request = $recording->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/42/domains', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"domain":"example.com","namespace":"icann"}', (string) $request->getBody());

        self::assertSame(7, $domain->id());
        self::assertSame('example.com', $domain->domain());
        self::assertSame('icann', $domain->namespace());
    }

    public function testAddDomainHnsPortalManagedSendsDnsHostingEnabledTrue(): void
    {
        $recording = $this->recording([new Response(201, [], $this->domainResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->addDomain('42', WebsiteDomainRequest::hns('name/')->withDnsHostingEnabled());

        self::assertSame(
            '{"domain":"name/","namespace":"hns","dns_hosting_enabled":true}',
            (string) $recording->lastRequest()->getBody(),
        );
    }

    public function testAddDomainHnsSelfManagedSendsDnsHostingEnabledFalse(): void
    {
        $recording = $this->recording([new Response(201, [], $this->domainResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->addDomain('42', WebsiteDomainRequest::hns('name/')->selfManaged());

        self::assertSame(
            '{"domain":"name/","namespace":"hns","dns_hosting_enabled":false}',
            (string) $recording->lastRequest()->getBody(),
        );
    }

    public function testAddDomainHnsOmittedDnsHostingWhenNotSpecified(): void
    {
        $recording = $this->recording([new Response(201, [], $this->domainResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->addDomain('42', WebsiteDomainRequest::hns('name/'));

        $body = (string) $recording->lastRequest()->getBody();
        self::assertStringNotContainsString('dns_hosting_enabled', $body);
        self::assertSame('{"domain":"name/","namespace":"hns"}', $body);
    }

    public function testAddDomainIcannExplicitSelfManagedSendsDnsHostingEnabledTrue(): void
    {
        $recording = $this->recording([new Response(201, [], $this->domainResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->addDomain('42', WebsiteDomainRequest::icann('example.com')->withDnsHostingEnabled());

        self::assertSame(
            '{"domain":"example.com","namespace":"icann","dns_hosting_enabled":true}',
            (string) $recording->lastRequest()->getBody(),
        );
    }

    public function testAddDomainPlatformOneClickSendsPlatformFields(): void
    {
        $recording = $this->recording([new Response(201, [], $this->domainResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->addDomain(
            '42',
            WebsiteDomainRequest::icann('label.pinner.xyz')
                ->asPlatformDomain(platformDomain: 'pinner.xyz', platformNamespace: 'icann', label: 'label'),
        );

        self::assertSame(
            '{"domain":"label.pinner.xyz","namespace":"icann","label":"label",'
            . '"platform_domain":"pinner.xyz","platform_namespace":"icann"}',
            (string) $recording->lastRequest()->getBody(),
        );
    }

    public function testAddDomainGenerateSendsTrue(): void
    {
        $recording = $this->recording([new Response(201, [], $this->domainResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->addDomain('42', WebsiteDomainRequest::hns('name/')->generateLabel());

        self::assertSame(
            '{"domain":"name/","namespace":"hns","generate":true}',
            (string) $recording->lastRequest()->getBody(),
        );
    }

    public function testAddDomainUrlEncodesWebsiteIdInPath(): void
    {
        $recording = $this->recording([new Response(201, [], $this->domainResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->addDomain('w/1', WebsiteDomainRequest::icann('example.com'));

        self::assertSame(self::BASE_URL . '/api/websites/w%2F1/domains', (string) $recording->lastRequest()->getUri());
    }

    public function testAddDomainNon2xxThrowsUnexpectedStatusWithoutSecret(): void
    {
        $recording = $this->recording([
            new Response(422, [], '{"error":{"reason":"INVALID_REQUEST","details":"bad domain s3cret-portal-key-55d2"}}'),
        ]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->addDomain('42', WebsiteDomainRequest::icann('example.com'));
            self::fail('Expected UnexpectedStatusCodeException.');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(422, $e->status());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    public function testAddDomainMalformedJsonThrowsResponseDecoding(): void
    {
        $recording = $this->recording([new Response(201, [], 'nope{')]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $this->expectException(ResponseDecodingException::class);

        $client->addDomain('42', WebsiteDomainRequest::icann('example.com'));
    }

    public function testAddDomainMissingRequiredFieldThrowsResponseDecoding(): void
    {
        $recording = $this->recording([new Response(201, [], '{"id":7}')]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $this->expectException(ResponseDecodingException::class);
        $this->expectExceptionMessage('Domain response is missing string field "domain".');

        $client->addDomain('42', WebsiteDomainRequest::icann('example.com'));
    }

    public function testAddDomainTransportErrorIsTypedAndNeverLeaksSecret(): void
    {
        $recording = $this->recording([
            new ConnectException('Connection refused', new Request('POST', self::BASE_URL . '/api/websites/42/domains')),
        ]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->addDomain('42', WebsiteDomainRequest::icann('example.com'));
            self::fail('Expected TransportException.');
        } catch (TransportException $e) {
            self::assertSame('POST', $e->method());
            self::assertSame(self::BASE_URL . '/api/websites/42/domains', $e->uri());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    public function testAddDomainRejectsInvalidNamespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WebsiteDomainRequest::create('example.com', 'dnssec');
    }

    public function testAddDomainMapsFullDomainResponseWithDelegationChecksAndSsl(): void
    {
        $recording = $this->recording([new Response(201, [], $this->fullDomainResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $domain = $client->addDomain('42', WebsiteDomainRequest::hns('name/')->withDnsHostingEnabled());

        self::assertSame(9, $domain->id());
        self::assertSame('name/', $domain->domain());
        self::assertSame('hns', $domain->namespace());
        self::assertTrue($domain->dnsHostingEnabled());
        self::assertSame('waiting_delegation', $domain->status());
        self::assertSame('_443._tcp.name/', $domain->ownerName());
        self::assertSame('3 1 1 abcdef', $domain->tlsaRdata());
        self::assertSame('partial', $domain->zoneName());

        $delegation = $domain->delegation();
        self::assertNotNull($delegation);
        self::assertSame('inline', $delegation->mode());
        self::assertSame(['ns1.pinner.xyz', 'ns2.pinner.xyz'], $delegation->nameservers());
        self::assertSame('active', $delegation->dnssec());
        self::assertNull($delegation->dnssecError());

        $parent = $delegation->parentRecords();
        self::assertCount(1, $parent);
        self::assertSame('NS', $parent[0]->type());
        self::assertSame('ns1.pinner.xyz', $parent[0]->value());
        self::assertSame('glue4.pinner.xyz', $parent[0]->address());
        self::assertNull($parent[0]->ns());

        $authoritative = $delegation->authoritativeRecords();
        self::assertCount(1, $authoritative);
        self::assertSame('TLSA', $authoritative[0]->type());
        self::assertSame('3 1 1 abcdef', $authoritative[0]->value());
        self::assertSame('_443._tcp.name/', $authoritative[0]->ns());

        $checks = $domain->checks();
        self::assertCount(2, $checks);
        self::assertSame('dnslink', $checks[0]->name());
        self::assertTrue($checks[0]->ok());
        self::assertSame('dnslink', $checks[1]->name());
        self::assertFalse($checks[1]->ok());
        self::assertSame('Publish this record', $checks[1]->message());
        self::assertSame('dnslink=/ipfs/QmCid', $checks[1]->expected());
        self::assertSame('', $checks[1]->found());

        $ssl = $domain->ssl();
        self::assertNotNull($ssl);
        self::assertSame('issuing', $ssl->status());
        self::assertNull($ssl->error());
        self::assertSame('2026-01-02T00:00:00Z', $ssl->issuedAt());
        self::assertSame('2026-01-02T00:00:00Z', $ssl->lastUpdatedAt());
    }

    public function testDeleteDomainSendsDeleteToExactPathAndAccepts204(): void
    {
        $recording = $this->recording([new Response(204, [], '')]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->deleteDomain('42', '7');

        $request = $recording->lastRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/42/domains/7', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
    }

    public function testDeleteDomainUrlEncodesBothIdsInPath(): void
    {
        $recording = $this->recording([new Response(204, [], '')]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->deleteDomain('w/1', 'd.2');

        self::assertSame(
            self::BASE_URL . '/api/websites/w%2F1/domains/d.2',
            (string) $recording->lastRequest()->getUri(),
        );
    }

    public function testDeleteDomainNon2xxThrowsUnexpectedStatusWithoutSecret(): void
    {
        $recording = $this->recording([new Response(404, [], '{"error":{"reason":"missing"}}')]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->deleteDomain('42', '7');
            self::fail('Expected UnexpectedStatusCodeException.');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(404, $e->status());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    public function testUpdateDomainSendsPatchWithExactBodyAndMapsResponse(): void
    {
        $recording = $this->recording([new Response(200, [], $this->domainResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $domain = $client->updateDomain('42', '7', new DomainUpdateRequest(false, true));

        $request = $recording->lastRequest();
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/42/domains/7', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"dns_hosting_enabled":false,"primary":true}', (string) $request->getBody());

        self::assertSame(7, $domain->id());
        self::assertSame('example.com', $domain->domain());
    }

    public function testUpdateDomainOmitsUnsetFieldsEntirely(): void
    {
        $recording = $this->recording([new Response(200, [], $this->domainResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->updateDomain('42', '7', new DomainUpdateRequest(dnsHostingEnabled: false));

        $body = (string) $recording->lastRequest()->getBody();
        self::assertSame('{"dns_hosting_enabled":false}', $body);
        self::assertStringNotContainsString('primary', $body);
    }

    public function testUpdateDomainEmitsEmptyObjectWhenBothUnset(): void
    {
        $recording = $this->recording([new Response(200, [], $this->domainResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->updateDomain('42', '7', new DomainUpdateRequest());

        self::assertSame('{}', (string) $recording->lastRequest()->getBody());
    }

    public function testUpdateDomainNon2xxThrowsUnexpectedStatusWithoutSecret(): void
    {
        $recording = $this->recording([new Response(422, [], '{"error":{"reason":"INVALID_REQUEST"}}')]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->updateDomain('42', '7', new DomainUpdateRequest(true));
            self::fail('Expected UnexpectedStatusCodeException.');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(422, $e->status());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
    }

    public function testDnsRequirementsSendsGetAndMapsDelegationRecords(): void
    {
        $recording = $this->recording([new Response(200, [], $this->fullDomainResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $domain = $client->dnsRequirements('42', '9');

        $request = $recording->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/42/domains/9/dns-requirements', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));

        self::assertSame(9, $domain->id());
        self::assertSame('name/', $domain->domain());
        self::assertSame('hns', $domain->namespace());
        self::assertSame('waiting_delegation', $domain->status());
        self::assertNotNull($domain->delegation());
        self::assertSame('inline', $domain->delegation()->mode());
    }

    public function testDnsRequirementsMapsIcannDnssecAndNonInlineAuthoritative(): void
    {
        $recording = $this->recording([new Response(200, [], $this->dnsRequirementsIcannResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $domain = $client->dnsRequirements('42', '11');

        $delegation = $domain->delegation();
        self::assertNotNull($delegation);
        self::assertNull($delegation->mode());
        self::assertSame(['ns1.gateway.test', 'ns2.gateway.test'], $delegation->nameservers());
        self::assertSame('pending', $delegation->dnssec());
        self::assertSame('Pending delegation', $delegation->dnssecError());

        $parent = $delegation->parentRecords();
        self::assertCount(2, $parent);
        self::assertSame('DS', $parent[0]->type());
        self::assertSame('12345 8 2 abcdef', $parent[0]->value());
        self::assertSame('NS', $parent[1]->type());
        self::assertSame('ns1.gateway.test', $parent[1]->value());

        $authoritative = $delegation->authoritativeRecords();
        self::assertCount(1, $authoritative);
        self::assertSame('TXT', $authoritative[0]->type());
        self::assertSame('dnslink=/ipfs/QmCid', $authoritative[0]->value());
    }

    public function testDnsRequirementsNon2xxThrowsUnexpectedStatusWithoutSecret(): void
    {
        $recording = $this->recording([new Response(422, [], '{"error":{"reason":"INVALID_REQUEST"}}')]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->dnsRequirements('42', '7');
            self::fail('Expected UnexpectedStatusCodeException.');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(422, $e->status());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
    }

    public function testVerifyDomainSendsPostAndMapsChecksAndStatus(): void
    {
        $recording = $this->recording([new Response(200, [], $this->fullDomainResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $domain = $client->verifyDomain('42', '9');

        $request = $recording->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/42/domains/9/verify', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('', (string) $request->getBody());

        self::assertSame(9, $domain->id());
        self::assertSame('waiting_delegation', $domain->status());
        self::assertCount(2, $domain->checks());
        self::assertFalse($domain->checks()[1]->ok());
    }

    public function testVerifyDomainNon2xxThrowsUnexpectedStatusWithoutSecret(): void
    {
        $recording = $this->recording([new Response(422, [], '{"error":{"reason":"DNS_VALIDATION_FAILED"}}')]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->verifyDomain('42', '9');
            self::fail('Expected UnexpectedStatusCodeException.');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(422, $e->status());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    public function testRepublishDaneSendsPostAndMapsResult(): void
    {
        $recording = $this->recording([new Response(200, [], $this->daneRepublishResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $result = $client->republishDane('42', '9');

        $request = $recording->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/42/domains/9/dane/republish', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('', (string) $request->getBody());

        self::assertInstanceOf(DaneRepublish::class, $result);
        self::assertSame(9, $result->id());
        self::assertSame('name/', $result->domain());
        self::assertSame('hns', $result->namespace());
        self::assertTrue($result->publishedToManagedZone());
        self::assertSame('3 1 1 abcdef', $result->tlsaRdata());
        self::assertSame('active', $result->status());
    }

    public function testRepublishDaneNon2xxThrowsUnexpectedStatusWithoutSecret(): void
    {
        $recording = $this->recording([new Response(409, [], '{"error":{"reason":"CONFLICT"}}')]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->republishDane('42', '9');
            self::fail('Expected UnexpectedStatusCodeException.');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(409, $e->status());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    public function testConvertToOnchainSendsPostAndMapsStatus(): void
    {
        $recording = $this->recording([new Response(200, [], $this->onchainDomainResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $domain = $client->convertToOnchain('42', '9');

        $request = $recording->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/42/domains/9/onchain', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('', (string) $request->getBody());

        self::assertSame(9, $domain->id());
        self::assertSame('name/', $domain->domain());
        self::assertSame('onchain_managed', $domain->status());
        self::assertNull($domain->delegation());
    }

    public function testConvertToOnchainNon2xxThrowsUnexpectedStatusWithoutSecret(): void
    {
        $recording = $this->recording([new Response(422, [], '{"error":{"reason":"INVALID_REQUEST","details":"not eligible"}}')]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->convertToOnchain('42', '9');
            self::fail('Expected UnexpectedStatusCodeException.');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(422, $e->status());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    public function testListPlatformDomainsSendsGetAndMapsEnvelope(): void
    {
        $recording = $this->recording([new Response(200, [], $this->platformDomainListResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $platforms = $client->listPlatformDomains();

        $request = $recording->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/platform-domains', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));

        self::assertCount(2, $platforms);
        $platform = $platforms[0];
        self::assertInstanceOf(PlatformDomain::class, $platform);
        self::assertSame(3, $platform->id());
        self::assertSame('pinner.xyz', $platform->domain());
        self::assertSame('icann', $platform->namespace());
        self::assertSame(101, $platform->zoneId());
        self::assertTrue($platform->enabled());
    }

    public function testListPlatformDomainsMissingDataThrowsResponseDecoding(): void
    {
        $recording = $this->recording([new Response(200, [], '{"total":2}')]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $this->expectException(ResponseDecodingException::class);
        $this->expectExceptionMessage('Platform domain list response is missing the "data" array.');

        $client->listPlatformDomains();
    }

    public function testCheckPlatformDomainAvailabilitySendsLabelQueryAndMapsResults(): void
    {
        $recording = $this->recording([new Response(200, [], $this->platformAvailabilityResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $result = $client->checkPlatformDomainAvailability('my-site');

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
        self::assertSame('icann', $result->results()[0]->namespace());
        self::assertTrue($result->results()[0]->available());
        self::assertFalse($result->results()[1]->available());
    }

    public function testCheckPlatformDomainAvailabilityUrlEncodesLabel(): void
    {
        $recording = $this->recording([new Response(200, [], $this->platformAvailabilityResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->checkPlatformDomainAvailability('my site/ü');

        self::assertSame(
            self::BASE_URL . '/api/websites/platform-domains/availability?label=my%20site%2F%C3%BC',
            (string) $recording->lastRequest()->getUri(),
        );
    }

    public function testCheckPlatformDomainAvailabilityEmptyLabelOmitsQuery(): void
    {
        $recording = $this->recording([new Response(200, [], $this->platformAvailabilityResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->checkPlatformDomainAvailability('');

        self::assertSame(
            self::BASE_URL . '/api/websites/platform-domains/availability',
            (string) $recording->lastRequest()->getUri(),
        );
    }

    public function testCheckPlatformDomainAvailabilityNon2xxThrowsUnexpectedStatusWithoutSecret(): void
    {
        $recording = $this->recording([new Response(422, [], '{"error":{"reason":"INVALID_REQUEST"}}')]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->checkPlatformDomainAvailability('my-site');
            self::fail('Expected UnexpectedStatusCodeException.');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(422, $e->status());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
    }

    public function testSslStatusSendsGetToExactPathAndMapsSslInfo(): void
    {
        $recording = $this->recording([new Response(200, [], $this->websiteSslStatusResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $ssl = $client->sslStatus('example.com');

        $request = $recording->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/websites/example.com/ssl-status', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));

        self::assertInstanceOf(SslStatusInfo::class, $ssl);
        self::assertSame('ready', $ssl->status());
        self::assertSame('2026-01-02T00:00:00Z', $ssl->issuedAt());
        self::assertSame('2026-01-02T00:00:00Z', $ssl->lastUpdatedAt());
        self::assertNull($ssl->error());
    }

    public function testSslStatusUrlEncodesDomainInPath(): void
    {
        $recording = $this->recording([new Response(200, [], $this->websiteSslStatusResponse())]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->sslStatus('sub.name.test');

        self::assertSame(
            self::BASE_URL . '/api/websites/sub.name.test/ssl-status',
            (string) $recording->lastRequest()->getUri(),
        );
    }

    public function testSslStatusReturnsNullWhenSslAbsent(): void
    {
        $recording = $this->recording([new Response(200, [], '{"id":7,"domain":"example.com","status":"active","target_type":"car","target_hash":"QmCid","validation_token":"tok","dns_hosting_enabled":false,"is_subdomain":false,"created":"2026-01-02T00:00:00Z","updated":"2026-01-02T00:00:00Z","expired":false}')]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        self::assertNull($client->sslStatus('example.com'));
    }

    public function testSslStatusNon2xxThrowsUnexpectedStatusWithoutSecret(): void
    {
        $recording = $this->recording([new Response(404, [], '{"error":{"reason":"missing"}}')]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->sslStatus('example.com');
            self::fail('Expected UnexpectedStatusCodeException.');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(404, $e->status());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    public function testSslStatusMalformedJsonThrowsResponseDecoding(): void
    {
        $recording = $this->recording([new Response(200, [], 'broken')]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $this->expectException(ResponseDecodingException::class);

        $client->sslStatus('example.com');
    }

    public function testSslStatusTransportErrorIsTypedAndNeverLeaksSecret(): void
    {
        $recording = $this->recording([
            new ConnectException('Connection refused', new Request('GET', self::BASE_URL . '/api/websites/example.com/ssl-status')),
        ]);
        $client = new IpfsDomainsClient($recording->transport(), self::BASE_URL, self::API_KEY);

        try {
            $client->sslStatus('example.com');
            self::fail('Expected TransportException.');
        } catch (TransportException $e) {
            self::assertSame('GET', $e->method());
            self::assertSame(self::BASE_URL . '/api/websites/example.com/ssl-status', $e->uri());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    private function domainListResponse(): string
    {
        return '{"data":[' . $this->domainResponseWithoutNested() . '],"total":1}';
    }

    private function domainResponse(): string
    {
        return '{"id":7,"domain":"example.com","namespace":"icann","dns_hosting_enabled":true,'
            . '"status":"active","gateway_host":"gw.example.com"}';
    }

    private function domainResponseWithoutNested(): string
    {
        return '{"id":7,"domain":"example.com","namespace":"icann","dns_hosting_enabled":true,'
            . '"status":"active","gateway_host":"gw.example.com"}';
    }

    private function fullDomainResponse(): string
    {
        return '{"id":9,"domain":"name/","namespace":"hns","dns_hosting_enabled":true,'
            . '"status":"waiting_delegation","owner_name":"_443._tcp.name/","tlsa_rdata":"3 1 1 abcdef",'
            . '"zone_name":"partial",'
            . '"delegation":{"mode":"inline","nameservers":["ns1.pinner.xyz","ns2.pinner.xyz"],'
            . '"dnssec":"active","parent_records":[{"type":"NS","value":"ns1.pinner.xyz",'
            . '"address":"glue4.pinner.xyz"}],'
            . '"authoritative_records":[{"type":"TLSA","value":"3 1 1 abcdef","ns":"_443._tcp.name/"}]},'
            . '"checks":[{"name":"dnslink","ok":true},{"name":"dnslink","ok":false,'
            . '"message":"Publish this record","expected":"dnslink=/ipfs/QmCid","found":""}],'
            . '"ssl":{"status":"issuing","issued_at":"2026-01-02T00:00:00Z",'
            . '"last_updated_at":"2026-01-02T00:00:00Z"}}';
    }

    private function dnsRequirementsIcannResponse(): string
    {
        return '{"id":11,"domain":"example.com","namespace":"icann","dns_hosting_enabled":false,'
            . '"status":"waiting_delegation","gateway_host":"gw.example.com",'
            . '"delegation":{"nameservers":["ns1.gateway.test","ns2.gateway.test"],'
            . '"dnssec":"pending","dnssec_error":"Pending delegation",'
            . '"parent_records":[{"type":"DS","value":"12345 8 2 abcdef"},'
            . '{"type":"NS","value":"ns1.gateway.test"}],'
            . '"authoritative_records":[{"type":"TXT","value":"dnslink=/ipfs/QmCid"}]}}';
    }

    private function daneRepublishResponse(): string
    {
        return '{"id":9,"domain":"name/","namespace":"hns","published_to_managed_zone":true,'
            . '"status":"active","tlsa_rdata":"3 1 1 abcdef"}';
    }

    private function onchainDomainResponse(): string
    {
        return '{"id":9,"domain":"name/","namespace":"hns","dns_hosting_enabled":false,'
            . '"status":"onchain_managed"}';
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

    private function websiteSslStatusResponse(): string
    {
        return '{"id":7,"domain":"example.com","status":"active","target_type":"car","target_hash":"QmCid",'
            . '"validation_token":"tok","dns_hosting_enabled":false,"is_subdomain":false,'
            . '"created":"2026-01-02T00:00:00Z","updated":"2026-01-02T00:00:00Z","expired":false,'
            . '"ssl":{"status":"ready","issued_at":"2026-01-02T00:00:00Z",'
            . '"last_updated_at":"2026-01-02T00:00:00Z"}}';
    }
}
