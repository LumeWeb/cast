<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Publish\Domain;

/**
 * Typed outcome of reading a bound domain's DNS requirements.
 *
 * Either the requirements were read (ok=true, with the serialized domain
 * carrying the records state) or the operation was refused with a typed
 * {@see DomainRefusal}. The serialized form is JSON-safe and never echoes a
 * refusal reason or credential that could leak internals.
 */
final class DomainDnsResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?DomainRefusal $refusal = null,
        public readonly ?Domain $domain = null,
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
                'domain' => self::serializeDomain($this->domain),
                'refusal' => null,
            ];
        }

        return [
            'ok' => false,
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

        // The full JSON-safe delegation bundle (nameservers, DNSSEC state,
        // parent/authoritative records) + checks are emitted through the same
        // serialization the panel uses, so the DNS route and the dashboard view
        // can never disagree about the nested shape.
        return DomainDashboardView::serializeDomain($domain);
    }
}
