<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Typed outcome of unbinding (deleting) a domain from the registered website.
 *
 * Either the domain was deleted (deleted=true, with the deleted binding id)
 * or the operation was refused with a typed {@see DomainRefusal}. The
 * serialized form is JSON-safe and never echoes a refusal reason or
 * credential that could leak internals.
 */
final class DomainDeleteResult
{
    public function __construct(
        public readonly bool $deleted,
        public readonly ?DomainRefusal $refusal = null,
        public readonly ?string $domainId = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->deleted) {
            return [
                'deleted' => true,
                'status' => 'deleted',
                'domain_id' => $this->domainId,
                'refusal' => null,
            ];
        }

        return [
            'deleted' => false,
            'status' => 'refused',
            'domain_id' => null,
            'refusal' => $this->refusal?->value,
        ];
    }
}
