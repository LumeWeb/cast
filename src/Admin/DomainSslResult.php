<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Typed outcome of reading a bound domain's SSL/TLS state.
 *
 * Either the portal answered (ok=true) with the serialized SSL block — or a
 * null ssl when the domain has none yet — or the operation was refused with a
 * typed {@see DomainRefusal}. The serialized form is JSON-safe and never
 * echoes a refusal reason or credential that could leak internals.
 */
final class DomainSslResult
{
    /**
     * @param array<string, mixed>|null $ssl
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?DomainRefusal $refusal = null,
        public readonly ?array $ssl = null,
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
                'ssl' => $this->ssl,
                'refusal' => null,
            ];
        }

        return [
            'ok' => false,
            'status' => 'refused',
            'ssl' => null,
            'refusal' => $this->refusal?->value,
        ];
    }
}
