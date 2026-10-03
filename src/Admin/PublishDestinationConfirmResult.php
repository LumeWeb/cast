<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Typed outcome of confirming the first-publish destination choice.
 *
 * Either the choice was frozen (confirmed=true, with the resulting lifecycle)
 * or it was refused with a typed {@see PublishDestinationRefusal}. The
 * serialized form is JSON-safe and never echoes a refusal reason that could
 * leak internals.
 */
final class PublishDestinationConfirmResult
{
    public function __construct(
        public readonly bool $confirmed,
        public readonly ?PublishDestinationRefusal $refusal = null,
        public readonly ?string $lifecycle = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->confirmed) {
            return [
                'confirmed' => true,
                'status' => 'confirmed',
                'refusal' => null,
                'lifecycle' => $this->lifecycle,
            ];
        }

        return [
            'confirmed' => false,
            'status' => 'refused',
            'refusal' => $this->refusal?->value,
            'lifecycle' => $this->lifecycle,
        ];
    }
}
