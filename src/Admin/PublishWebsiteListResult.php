<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Typed outcome of the guided "available websites" picker read.
 *
 * Either the account's websites were listed (listed=true, with JSON-safe rows
 * of {website_id, domain, status, target_hash, target_type}) or the read was
 * refused with a typed {@see PublishWebsiteRefusal}. The workspace's own
 * already-attached website (the one carrying the preserved CID, or the one
 * recorded in the identity) is excluded so the picker never offers it up for a
 * second link. The serialized form is JSON-safe: identifiers and the bound
 * domain only.
 */
final class PublishWebsiteListResult
{
    /**
     * @param list<array{website_id: string, domain: string, status: string, target_hash: string, target_type: string}> $websites
     */
    public function __construct(
        public readonly bool $listed,
        public readonly ?PublishWebsiteRefusal $refusal = null,
        public readonly array $websites = [],
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
                'websites' => $this->websites,
                'refusal' => null,
            ];
        }

        return [
            'listed' => false,
            'status' => 'refused',
            'websites' => [],
            'refusal' => $this->refusal?->value,
        ];
    }
}
