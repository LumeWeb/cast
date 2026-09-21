<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Ipfs\PlatformDomain;

/**
 * Typed outcome of listing the portal platform (free-subdomain) roots.
 *
 * Either the roots were listed (ok=true, with the serialized platform list) or
 * the operation was refused with a typed {@see DomainRefusal}. A pre-bind
 * catalog read, it needs only the complete deployment identity — no website
 * identity yet. The serialized form is JSON-safe and never echoes a refusal
 * reason or credential that could leak internals.
 */
final class DomainPlatformListResult
{
    /**
     * @param list<PlatformDomain> $platformDomains
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?DomainRefusal $refusal = null,
        public readonly array $platformDomains = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->ok) {
            return [
                'ok' => true,
                'status' => 'ok',
                'platform_domains' => array_map(
                    static fn (PlatformDomain $domain): array => self::serializePlatformDomain($domain),
                    $this->platformDomains,
                ),
                'refusal' => null,
            ];
        }

        return [
            'ok' => false,
            'status' => 'refused',
            'platform_domains' => [],
            'refusal' => $this->refusal?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializePlatformDomain(PlatformDomain $domain): array
    {
        return [
            'id' => $domain->id(),
            'domain' => $domain->domain(),
            'namespace' => $domain->namespace(),
            'zone_id' => $domain->zoneId(),
            'enabled' => $domain->enabled(),
        ];
    }
}
