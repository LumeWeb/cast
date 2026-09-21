<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Typed outcome of the guided "create a new website" action.
 *
 * Either the website was created from the preserved CID and the identity half
 * recorded (created=true, with identifiers/domain) or it was refused
 * with a typed {@see PublishWebsiteRefusal}. The serialized form is JSON-safe:
 * identifiers and the bound domain only — never credentials or internals.
 */
final class PublishWebsiteCreateResult
{
    public function __construct(
        public readonly bool $created,
        public readonly ?PublishWebsiteRefusal $refusal = null,
        public readonly ?string $websiteId = null,
        public readonly ?string $websiteName = null,
        public readonly ?string $domain = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->created) {
            return [
                'created' => true,
                'status' => 'created',
                'website_id' => $this->websiteId,
                'website_name' => $this->websiteName,
                'domain' => $this->domain,
                'refusal' => null,
            ];
        }

        return [
            'created' => false,
            'status' => 'refused',
            'website_id' => null,
            'website_name' => null,
            'domain' => null,
            'refusal' => $this->refusal?->value,
        ];
    }
}
