<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Publish\Domain;

/**
 * Typed outcome of binding a domain to the registered website.
 *
 * Either the domain was bound (bound=true, with the serialized bound domain)
 * or the operation was refused with a typed {@see DomainRefusal}. The
 * serialized form is JSON-safe and never echoes a refusal reason or
 * credential that could leak internals.
 */
final class DomainBindResult
{
    public function __construct(
        public readonly bool $bound,
        public readonly ?DomainRefusal $refusal = null,
        public readonly ?Domain $domain = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->bound) {
            return [
                'bound' => true,
                'status' => 'bound',
                'domain' => self::serializeDomain($this->domain),
                'refusal' => null,
            ];
        }

        return [
            'bound' => false,
            'status' => 'refused',
            'domain' => null,
            'refusal' => $this->refusal?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeDomain(?Domain $domain): array
    {
        if ($domain === null) {
            return [];
        }

        return [
            'id' => $domain->id,
            'domain' => $domain->domain,
            'namespace' => $domain->namespace,
            'dns_hosting_enabled' => $domain->dnsHostingEnabled,
            'status' => $domain->status,
            'gateway_host' => $domain->gatewayHost,
        ];
    }
}
