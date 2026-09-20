<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Ipfs\WebsiteValidation;

/**
 * Typed outcome of running a DNS validation check against the registered
 * website (POST /api/websites/{id}/validate).
 *
 * Either the validation ran (ok=true, with the serialized WebsiteValidation
 * carrying the server-computed valid flag, message and per-record checks) or
 * the operation was refused with a typed {@see DomainRefusal}. The serialized
 * form is JSON-safe and never echoes a refusal reason or credential that could
 * leak internals; every check value comes from the server and is rendered
 * verbatim by the client.
 */
final class DomainValidateResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?DomainRefusal $refusal = null,
        public readonly ?WebsiteValidation $validation = null,
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
                'validation' => self::serializeValidation($this->validation),
                'refusal' => null,
            ];
        }

        return [
            'ok' => false,
            'status' => 'refused',
            'validation' => null,
            'refusal' => $this->refusal?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeValidation(?WebsiteValidation $validation): array
    {
        if ($validation === null) {
            return [];
        }

        return [
            'id' => $validation->id(),
            'domain' => $validation->domain(),
            'valid' => $validation->valid(),
            'message' => $validation->message(),
            'reason' => $validation->reason(),
            'checks' => array_map(DomainDashboardView::serializeCheck(...), $validation->checks()),
        ];
    }
}
