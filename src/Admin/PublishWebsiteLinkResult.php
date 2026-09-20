<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Typed outcome of the guided "link an existing website" action.
 *
 * Either the website was attached to the workspace and the identity half
 * recorded (linked=true, with the website id) or it was refused with a typed
 * {@see PublishWebsiteRefusal}. The serialized form is JSON-safe and never
 * echoes a refusal reason or credential that could leak internals.
 */
final class PublishWebsiteLinkResult
{
    public function __construct(
        public readonly bool $linked,
        public readonly ?PublishWebsiteRefusal $refusal = null,
        public readonly ?string $websiteId = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->linked) {
            return [
                'linked' => true,
                'status' => 'linked',
                'website_id' => $this->websiteId,
                'refusal' => null,
            ];
        }

        return [
            'linked' => false,
            'status' => 'refused',
            'website_id' => null,
            'refusal' => $this->refusal?->value,
        ];
    }
}
