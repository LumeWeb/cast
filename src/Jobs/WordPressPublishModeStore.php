<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

use LumeWeb\Cast\Persistence\OptionGateway;

/**
 * WordPress {@see PublishModeStore} adapter.
 *
 * Persists the mode as a single non-autoloaded option
 * (`cast_publish_mode`), mirroring the run/identity option style. A missing or
 * corrupt stored value reads safely as {@see PublishMode::Manual}, so a fresh
 * install never auto-publishes until the operator opts in (manual mode default).
 */
final class WordPressPublishModeStore implements PublishModeStore
{
    public const OPTION_KEY = 'cast_publish_mode';

    public function __construct(private readonly OptionGateway $options)
    {
    }

    public function mode(): PublishMode
    {
        return PublishMode::fromStored($this->options->get(self::OPTION_KEY, null));
    }

    public function setMode(PublishMode $mode): void
    {
        $this->options->update(self::OPTION_KEY, $mode->value, false);
    }
}
