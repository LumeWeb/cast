<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Typed outcome of saving a destination as an editable draft.
 *
 * Either the draft was persisted (saved=true, with the resulting lifecycle)
 * or it was refused with a typed {@see PublishDestinationRefusal}. The
 * serialized form is JSON-safe and never echoes a refusal reason that could
 * leak internals.
 */
final class PublishDestinationSaveResult
{
    public function __construct(
        public readonly bool $saved,
        public readonly ?PublishDestinationRefusal $refusal = null,
        public readonly ?string $lifecycle = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->saved) {
            return [
                'saved' => true,
                'status' => 'saved',
                'refusal' => null,
                'lifecycle' => $this->lifecycle,
            ];
        }

        return [
            'saved' => false,
            'status' => 'refused',
            'refusal' => $this->refusal?->value,
            'lifecycle' => $this->lifecycle,
        ];
    }
}
