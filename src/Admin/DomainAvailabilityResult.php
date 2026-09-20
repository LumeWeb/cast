<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Ipfs\PlatformAvailability;
use LumeWeb\Cast\Ipfs\PlatformAvailabilityResult;

/**
 * Typed outcome of checking whether a candidate subdomain label is claimable
 * on the portal platform roots.
 *
 * Either the portal answered (ok=true, with the serialized label + per-root
 * availability results) or the operation was refused with a typed
 * {@see DomainRefusal}. A pre-bind catalog read, it needs only the complete
 * deployment identity — no website identity yet. The serialized form is
 * JSON-safe and never echoes a refusal reason or credential that could leak
 * internals.
 */
final class DomainAvailabilityResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?DomainRefusal $refusal = null,
        public readonly ?PlatformAvailability $availability = null,
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
                'availability' => self::serializeAvailability($this->availability),
                'refusal' => null,
            ];
        }

        return [
            'ok' => false,
            'status' => 'refused',
            'availability' => null,
            'refusal' => $this->refusal?->value,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function serializeAvailability(?PlatformAvailability $availability): ?array
    {
        if ($availability === null) {
            return null;
        }

        return [
            'label' => $availability->label(),
            'results' => array_map(
                static fn (PlatformAvailabilityResult $result): array => [
                    'platform_domain' => $result->platformDomain(),
                    'namespace' => $result->namespace(),
                    'available' => $result->available(),
                ],
                $availability->results(),
            ),
        ];
    }
}
