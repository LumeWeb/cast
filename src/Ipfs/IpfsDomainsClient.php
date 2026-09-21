<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\HttpException;
use LumeWeb\Cast\Http\HttpTransport;
use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * Real domain/DNS/SSL adapter on the shared PSR-18 transport, mapping the
 * ipfs-sdk WebsitesService domain-binding endpoints exactly:
 *
 *   ListDomains            GET    /api/websites/{id}/domains
 *   BindDomain             POST   /api/websites/{id}/domains
 *   UpdateDomain           PATCH  /api/websites/{id}/domains/{domainId}
 *   UnbindDomain           DELETE /api/websites/{id}/domains/{domainId}
 *   VerifyDomain           POST   /api/websites/{id}/domains/{domainId}/verify
 *   GetDomainDNSRequirements GET  /api/websites/{id}/domains/{domainId}/dns-requirements
 *   RepublishDANE          POST   /api/websites/{id}/domains/{domainId}/dane/republish
 *   ConvertDomainToOnChain POST   /api/websites/{id}/domains/{domainId}/onchain
 *   ListPlatformDomains    GET    /api/websites/platform-domains
 *   CheckPlatformDomainAvailability GET /api/websites/platform-domains/availability?label=…
 *   GetSSLStatus           GET    /api/websites/{domain}/ssl-status
 *
 * Path segments and query values are percent-encoded with rawurlencode; reads
 * send no Content-Type, writes send application/json. The API key is a bearer
 * credential that only ever appears on the wire; the typed HttpException
 * family is preserved. Unlike the Go SDK's ConvertDomainToOnChain, an
 * "already on-chain" 422 is not silently tolerated — it surfaces as
 * UnexpectedStatusCodeException so callers decide.
 */
final class IpfsDomainsClient
{
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {
    }

    /**
     * GET /api/websites/{id}/domains — domains bound to a website.
     *
     * @return list<WebsiteDomain>
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function listDomains(string $websiteId): array
    {
        $uri = $this->baseUrl . '/api/websites/' . rawurlencode($websiteId) . '/domains';
        $response = $this->transport->send('GET', $uri, $this->authHeaders());
        $response->requireSuccess();

        $data = $response->json();
        if (!isset($data['data']) || !is_array($data['data'])) {
            throw new ResponseDecodingException('Domain list response is missing the "data" array.');
        }

        return array_map(
            static fn (mixed $item): WebsiteDomain => WebsiteDomain::fromArray($item),
            array_values($data['data']),
        );
    }

    /**
     * POST /api/websites/{id}/domains — bind a domain to a website (ICANN or
     * HNS, portal-managed or self-managed DNS, one-click platform subdomain,
     * or server-generated label).
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function addDomain(string $websiteId, WebsiteDomainRequest $request): WebsiteDomain
    {
        $uri = $this->baseUrl . '/api/websites/' . rawurlencode($websiteId) . '/domains';
        $response = $this->transport->send('POST', $uri, $this->jsonHeaders(), $this->encode($request->toArray()));
        $response->requireSuccess();

        return WebsiteDomain::fromArray($response->json());
    }

    /**
     * DELETE /api/websites/{id}/domains/{domainId} — unbind a domain from a
     * website. The portal answers 204 No Content on success.
     *
     * @throws HttpException transport/status failures (typed family preserved)
     */
    public function deleteDomain(string $websiteId, string $domainId): void
    {
        $uri = $this->baseUrl . '/api/websites/' . rawurlencode($websiteId) . '/domains/' . rawurlencode($domainId);
        $response = $this->transport->send('DELETE', $uri, $this->authHeaders());
        $response->requireSuccess();
    }

    /**
     * PATCH /api/websites/{id}/domains/{domainId} — update per-domain DNS
     * control (dns_hosting_enabled, primary). Unset fields are omitted so the
     * server leaves them unchanged.
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function updateDomain(string $websiteId, string $domainId, DomainUpdateRequest $request): WebsiteDomain
    {
        $uri = $this->baseUrl . '/api/websites/' . rawurlencode($websiteId) . '/domains/' . rawurlencode($domainId);
        $response = $this->transport->send('PATCH', $uri, $this->jsonHeaders(), $this->encode($request->toArray()));
        $response->requireSuccess();

        return WebsiteDomain::fromArray($response->json());
    }

    /**
     * GET /api/websites/{id}/domains/{domainId}/dns-requirements — the DNS
     * records (DS/NS/glue/TLSA parent + authoritative) the user must publish
     * to complete delegation.
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function dnsRequirements(string $websiteId, string $domainId): WebsiteDomain
    {
        $uri = $this->baseUrl . '/api/websites/' . rawurlencode($websiteId) . '/domains/' . rawurlencode($domainId)
            . '/dns-requirements';
        $response = $this->transport->send('GET', $uri, $this->authHeaders());
        $response->requireSuccess();

        return WebsiteDomain::fromArray($response->json());
    }

    /**
     * POST /api/websites/{id}/domains/{domainId}/verify — trigger a
     * re-check of the domain's delegation records.
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function verifyDomain(string $websiteId, string $domainId): WebsiteDomain
    {
        $uri = $this->baseUrl . '/api/websites/' . rawurlencode($websiteId) . '/domains/' . rawurlencode($domainId)
            . '/verify';
        $response = $this->transport->send('POST', $uri, $this->jsonHeaders());
        $response->requireSuccess();

        return WebsiteDomain::fromArray($response->json());
    }

    /**
     * POST /api/websites/{id}/domains/{domainId}/dane/republish — force
     * re-publication of the binding's DANE TLSA records into the managed
     * authoritative zone.
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function republishDane(string $websiteId, string $domainId): DaneRepublish
    {
        $uri = $this->baseUrl . '/api/websites/' . rawurlencode($websiteId) . '/domains/' . rawurlencode($domainId)
            . '/dane/republish';
        $response = $this->transport->send('POST', $uri, $this->jsonHeaders());
        $response->requireSuccess();

        return DaneRepublish::fromArray($response->json());
    }

    /**
     * POST /api/websites/{id}/domains/{domainId}/onchain — reclassify a bound
     * domain as on-chain managed (the one-way transition away from the
     * portal-managed zone + DNSSEC).
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function convertToOnchain(string $websiteId, string $domainId): WebsiteDomain
    {
        $uri = $this->baseUrl . '/api/websites/' . rawurlencode($websiteId) . '/domains/' . rawurlencode($domainId)
            . '/onchain';
        $response = $this->transport->send('POST', $uri, $this->jsonHeaders());
        $response->requireSuccess();

        return WebsiteDomain::fromArray($response->json());
    }

    /**
     * GET /api/websites/platform-domains — platform (free-subdomain) roots
     * available for websites.
     *
     * @return list<PlatformDomain>
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function listPlatformDomains(): array
    {
        $uri = $this->baseUrl . '/api/websites/platform-domains';
        $response = $this->transport->send('GET', $uri, $this->authHeaders());
        $response->requireSuccess();

        $data = $response->json();
        if (!isset($data['data']) || !is_array($data['data'])) {
            throw new ResponseDecodingException('Platform domain list response is missing the "data" array.');
        }

        return array_map(
            static fn (mixed $item): PlatformDomain => PlatformDomain::fromArray($item),
            array_values($data['data']),
        );
    }

    /**
     * GET /api/websites/platform-domains/availability — whether a candidate
     * subdomain label is claimable on each enabled platform root. An empty
     * label omits the query parameter entirely.
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function checkPlatformDomainAvailability(string $label): PlatformAvailability
    {
        $uri = $this->baseUrl . '/api/websites/platform-domains/availability';
        if ($label !== '') {
            $uri .= '?label=' . rawurlencode($label);
        }
        $response = $this->transport->send('GET', $uri, $this->authHeaders());
        $response->requireSuccess();

        return PlatformAvailability::fromArray($response->json());
    }

    /**
     * GET /api/websites/{domain}/ssl-status — SSL state for a bound domain.
     * Returns null when the domain has no SSL block yet.
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function sslStatus(string $domain): ?SslStatusInfo
    {
        $uri = $this->baseUrl . '/api/websites/' . rawurlencode($domain) . '/ssl-status';
        $response = $this->transport->send('GET', $uri, $this->authHeaders());
        $response->requireSuccess();

        $data = $response->json();
        if (!isset($data['ssl']) || !is_array($data['ssl'])) {
            return null;
        }

        return SslStatusInfo::fromArray($data['ssl']);
    }

    /**
     * @param array<string, string|bool|int> $body
     */
    private function encode(array $body): string
    {
        return json_encode(
            $body,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT,
        );
    }

    /**
     * Headers for requests with no body (GET, DELETE).
     *
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Headers for requests that carry a JSON body or trigger server-side work
     * (POST, PATCH).
     *
     * @return array<string, string>
     */
    private function jsonHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }
}
