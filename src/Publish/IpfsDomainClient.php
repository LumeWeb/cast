<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

use LumeWeb\Cast\Http\HttpException;
use LumeWeb\Cast\Ipfs\IpfsDomainsClient;
use LumeWeb\Cast\Ipfs\IpfsWebsitesClient;
use LumeWeb\Cast\Ipfs\PlatformAvailability;
use LumeWeb\Cast\Ipfs\PlatformDomain;
use LumeWeb\Cast\Ipfs\SslStatusInfo;
use LumeWeb\Cast\Ipfs\WebsiteDomain as IpfsWebsiteDomain;
use LumeWeb\Cast\Ipfs\WebsiteDomainRequest;
use LumeWeb\Cast\Ipfs\WebsiteValidation;

/**
 * Publish DomainClient adapter over the ipfs-sdk domains client. bind() maps
 * the chosen name + namespace onto a bind request and delegates to
 * IpfsDomainsClient::addDomain(); list(), dnsRequirements(), verify() and
 * delete() delegate to the matching SDK calls. Every WebsiteDomain response is
 * translated onto the Publish Domain value the orchestration consumes: the
 * numeric binding id becomes the string identity the registry persists while
 * domain, namespace, DNS-hosting flag, lifecycle status and gateway host pass
 * through. The read-only platform and SSL helpers sit on this concrete client
 * (no Publish value exists for them yet) and return the ipfs-sdk DTOs
 * unchanged. Every typed HttpException is rethrown as a DomainClientException
 * carrying the original as $previous; the message is always secret-safe because
 * the bearer API key only ever appears on the wire and never in the typed error
 * text being wrapped.
 */
final class IpfsDomainClient implements DomainClient
{
    /**
     * @param IpfsWebsitesClient|null $websites the raw websites client the
     *   website-level DNS validation (POST /api/websites/{id}/validate) is
     *   delegated to. Null keeps the adapter constructible from just the
     *   domains client (legacy/test call-sites that never validate); a
     *   validate() call on such an adapter refuses with a
     *   DomainClientException instead of touching the network.
     */
    public function __construct(
        private readonly IpfsDomainsClient $domains,
        private readonly ?IpfsWebsitesClient $websites = null,
    ) {
    }

    public function bind(string $websiteId, string $domain, string $namespace): Domain
    {
        try {
            $bound = $this->domains->addDomain($websiteId, WebsiteDomainRequest::create($domain, $namespace));
        } catch (HttpException $exception) {
            throw new DomainClientException($exception->getMessage(), 0, $exception);
        }

        return $this->map($bound);
    }

    /**
     * @return list<Domain>
     */
    public function list(string $websiteId): array
    {
        try {
            $domains = $this->domains->listDomains($websiteId);
        } catch (HttpException $exception) {
            throw new DomainClientException($exception->getMessage(), 0, $exception);
        }

        return array_map($this->map(...), $domains);
    }

    public function dnsRequirements(string $websiteId, string $domainId): Domain
    {
        try {
            $domain = $this->domains->dnsRequirements($websiteId, $domainId);
        } catch (HttpException $exception) {
            throw new DomainClientException($exception->getMessage(), 0, $exception);
        }

        return $this->map($domain);
    }

    public function verify(string $websiteId, string $domainId): Domain
    {
        try {
            $domain = $this->domains->verifyDomain($websiteId, $domainId);
        } catch (HttpException $exception) {
            throw new DomainClientException($exception->getMessage(), 0, $exception);
        }

        return $this->map($domain);
    }

    public function validate(string $websiteId): WebsiteValidation
    {
        if ($this->websites === null) {
            // A validate() on an adapter built without the websites client is a
            // wiring bug; surface it as the typed family (never a raw value) so
            // the Admin layer maps it to the same request-failed refusal and no
            // response is attempted.
            throw new DomainClientException('Website validation is not available on this domain client.');
        }

        try {
            return $this->websites->validate($websiteId);
        } catch (HttpException $exception) {
            throw new DomainClientException($exception->getMessage(), 0, $exception);
        }
    }

    public function delete(string $websiteId, string $domainId): void
    {
        try {
            $this->domains->deleteDomain($websiteId, $domainId);
        } catch (HttpException $exception) {
            throw new DomainClientException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @return list<PlatformDomain>
     *
     * @throws DomainClientException
     */
    public function listPlatformDomains(): array
    {
        try {
            return $this->domains->listPlatformDomains();
        } catch (HttpException $exception) {
            throw new DomainClientException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @throws DomainClientException
     */
    public function checkPlatformDomainAvailability(string $label): PlatformAvailability
    {
        try {
            return $this->domains->checkPlatformDomainAvailability($label);
        } catch (HttpException $exception) {
            throw new DomainClientException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @throws DomainClientException
     */
    public function sslStatus(string $domain): ?SslStatusInfo
    {
        try {
            return $this->domains->sslStatus($domain);
        } catch (HttpException $exception) {
            throw new DomainClientException($exception->getMessage(), 0, $exception);
        }
    }

    private function map(IpfsWebsiteDomain $domain): Domain
    {
        return new Domain(
            (string) $domain->id(),
            $domain->domain(),
            $domain->namespace(),
            $domain->dnsHostingEnabled(),
            $domain->status(),
            $domain->gatewayHost(),
            $domain->delegation(),
            $domain->checks(),
        );
    }
}
