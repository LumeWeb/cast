<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

/**
 * Immutable request payload for the ipfs-sdk UpdateDomain (PATCH
 * /api/websites/{id}/domains/{domainId}) call. Mirrors the swagger
 * DomainUpdateRequest fields: dns_hosting_enabled turns portal-managed DNS
 * hosting for the binding on/off, primary marks it as the website's apex
 * binding. An unset field is omitted from the encoded JSON so the server
 * leaves it unchanged.
 */
final class DomainUpdateRequest
{
    public function __construct(
        private readonly ?bool $dnsHostingEnabled = null,
        private readonly ?bool $primary = null,
    ) {
    }

    /**
     * @return array<string, bool> Body in the exact key order the portal expects.
     */
    public function toArray(): array
    {
        $body = [];
        if ($this->dnsHostingEnabled !== null) {
            $body['dns_hosting_enabled'] = $this->dnsHostingEnabled;
        }
        if ($this->primary !== null) {
            $body['primary'] = $this->primary;
        }

        return $body;
    }
}
