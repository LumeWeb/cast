<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

use LumeWeb\Cast\Ipfs\PlatformAvailability;
use LumeWeb\Cast\Ipfs\PlatformDomain;
use LumeWeb\Cast\Ipfs\SslStatusInfo;
use LumeWeb\Cast\Ipfs\WebsiteValidation;

/**
 * The portal domain surface the orchestration needs once a website exists (the
 * domain-setup step): bind a domain for the chosen namespace, list the already-bound
 * ones, read the DNS records the user must publish, trigger a re-verify, and
 * unbind. Alongside those site-locked operations sit the pre-bind catalog
 * reads the admin "choose a domain" UX uses before any binding exists (the
 * platform free-subdomain roots and per-label availability) and the SSL state
 * read for an already-bound domain. A fake implements it in tests;
 * IpfsDomainClient maps it onto the ipfs-sdk WebsitesService domain endpoints.
 * Throwing DomainClientException signals a failure the orchestration surfaces
 * without losing the website.
 */
interface DomainClient
{
    /**
     * @return list<Domain>
     */
    public function list(string $websiteId): array;

    public function bind(string $websiteId, string $domain, string $namespace): Domain;

    public function dnsRequirements(string $websiteId, string $domainId): Domain;

    public function verify(string $websiteId, string $domainId): Domain;

    /**
     * @throws DomainClientException
     */
    public function validate(string $websiteId): WebsiteValidation;

    public function delete(string $websiteId, string $domainId): void;

    /**
     * @return list<PlatformDomain>
     *
     * @throws DomainClientException
     */
    public function listPlatformDomains(): array;

    /**
     * @throws DomainClientException
     */
    public function checkPlatformDomainAvailability(string $label): PlatformAvailability;

    /**
     * @throws DomainClientException
     */
    public function sslStatus(string $domain): ?SslStatusInfo;
}
