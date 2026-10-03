<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Publish\PublishDestinationState;

/**
 * The JSON-safe read model of the persisted first-publish destination setup.
 *
 * `lifecycle` is the destination's setup lifecycle ('draft', 'confirmed',
 * 'created_or_attached') and `destination` its full field serialization —
 * or both null when no setup exists yet ("choose an address").
 */
final class PublishDestinationView
{
    /**
     * @param array<string, scalar|null>|null $destination
     */
    public function __construct(
        public readonly ?string $lifecycle = null,
        public readonly ?array $destination = null,
    ) {
    }

    public static function fromState(?PublishDestinationState $state): self
    {
        if ($state === null) {
            return new self();
        }

        return new self($state->lifecycle->value, $state->destination->toArray());
    }

    /**
     * @return array{lifecycle: string|null, destination: array<string, scalar|null>|null}
     */
    public function toArray(): array
    {
        return [
            'lifecycle' => $this->lifecycle,
            'destination' => $this->destination,
        ];
    }
}
