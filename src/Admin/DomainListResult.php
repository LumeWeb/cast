<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Publish\Domain;

/**
 * Typed outcome of listing the domains bound to the registered website.
 *
 * Either the bound domains were listed (listed=true, with the serialized
 * domain list) or the operation was refused with a typed
 * {@see DomainRefusal}. The serialized form is JSON-safe and never echoes a
 * refusal reason or credential that could leak internals.
 */
final class DomainListResult
{
    /**
     * @param list<Domain> $domains
     */
    public function __construct(
        public readonly bool $listed,
        public readonly ?DomainRefusal $refusal = null,
        public readonly array $domains = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->listed) {
            return [
                'listed' => true,
                'status' => 'ok',
                'domains' => array_map(static fn (Domain $domain): array => self::serializeDomain($domain), $this->domains),
                'refusal' => null,
            ];
        }

        return [
            'listed' => false,
            'status' => 'refused',
            'domains' => [],
            'refusal' => $this->refusal?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeDomain(Domain $domain): array
    {
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
