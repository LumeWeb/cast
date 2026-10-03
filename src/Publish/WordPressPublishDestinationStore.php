<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

use LumeWeb\Cast\Persistence\OptionGateway;

/**
 * WordPress {@see PublishDestinationStore} adapter.
 *
 * Persists the destination setup as a single non-autoloaded option
 * (`cast_publish_destination`), mirroring the run/identity option style. A
 * missing or corrupt stored value reads safely as null ("no setup yet").
 *
 * The lifecycle guard lives here: a confirmed destination is rejected on any
 * change, and a created/attached destination is never overwritten, so the
 * irreversible first-publish address cannot drift once the website exists.
 */
final class WordPressPublishDestinationStore implements PublishDestinationStore
{
    public const OPTION_KEY = 'cast_publish_destination';

    public function __construct(private readonly OptionGateway $options)
    {
    }

    public function read(): ?PublishDestinationState
    {
        return PublishDestinationState::fromArray($this->options->get(self::OPTION_KEY, null));
    }

    public function saveDraft(PublishDestination $destination): void
    {
        $current = $this->read();
        if ($current !== null && $current->lifecycle !== PublishDestinationLifecycle::Draft) {
            throw new \InvalidArgumentException(sprintf(
                'The publish destination is %s and can no longer be edited.',
                $current->lifecycle->value,
            ));
        }

        $this->write(PublishDestinationState::draft($destination));
    }

    public function confirm(PublishDestination $destination): void
    {
        $current = $this->read();
        if ($current !== null) {
            if ($current->lifecycle === PublishDestinationLifecycle::CreatedOrAttached) {
                throw new \InvalidArgumentException(
                    'The publish destination is created or attached and can no longer be changed.',
                );
            }

            if (
                $current->lifecycle === PublishDestinationLifecycle::Confirmed
                && $current->destination->toArray() !== $destination->toArray()
            ) {
                throw new \InvalidArgumentException(
                    'A confirmed publish destination cannot be changed; cancel the confirmed choice first.',
                );
            }
        }

        $this->write(PublishDestinationState::confirmed($destination));
    }

    public function markCreatedOrAttached(): void
    {
        $current = $this->read();
        // Idempotent: a terminal state stays terminal on retry.
        if (
            $current === null
            || $current->lifecycle === PublishDestinationLifecycle::Draft
        ) {
            throw new \InvalidArgumentException(
                'Cannot mark a publish destination created or attached before it is confirmed.',
            );
        }

        if ($current->lifecycle === PublishDestinationLifecycle::CreatedOrAttached) {
            return;
        }

        $this->write($current->withLifecycle(PublishDestinationLifecycle::CreatedOrAttached));
    }

    private function write(PublishDestinationState $state): void
    {
        $value = $state->toArray();
        if (!$this->options->add(self::OPTION_KEY, $value, false)) {
            $this->options->update(self::OPTION_KEY, $value, false);
        }
    }
}
