<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * The persisted destination setup: the chosen {@see PublishDestination} plus
 * its setup {@see PublishDestinationLifecycle}.
 *
 * (De)serialization is explicit and strict: absent or corrupt stored data
 * reads as null ("no setup") instead of a partial aggregate, mirroring the
 * run/identity option adapters.
 */
final class PublishDestinationState
{
    private function __construct(
        public readonly PublishDestination $destination,
        public readonly PublishDestinationLifecycle $lifecycle,
    ) {
    }

    public static function draft(PublishDestination $destination): self
    {
        return new self($destination, PublishDestinationLifecycle::Draft);
    }

    public static function confirmed(PublishDestination $destination): self
    {
        return new self($destination, PublishDestinationLifecycle::Confirmed);
    }

    public function withLifecycle(PublishDestinationLifecycle $lifecycle): self
    {
        return new self($this->destination, $lifecycle);
    }

    /** @return array{destination: array<string, scalar|null>, lifecycle: string} */
    public function toArray(): array
    {
        return [
            'destination' => $this->destination->toArray(),
            'lifecycle' => $this->lifecycle->value,
        ];
    }

    /** @param mixed $value */
    public static function fromArray(mixed $value): ?self
    {
        if (!is_array($value)) {
            return null;
        }

        $destination = PublishDestination::fromArray($value['destination'] ?? null);
        $lifecycle = PublishDestinationLifecycle::tryFrom((string) ($value['lifecycle'] ?? ''));

        if ($destination === null || $lifecycle === null) {
            return null;
        }

        return new self($destination, $lifecycle);
    }
}
