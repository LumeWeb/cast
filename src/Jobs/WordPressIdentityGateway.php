<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

use LumeWeb\Cast\Persistence\OptionGateway;

/**
 * WordPress {@see IdentityGateway} adapter.
 *
 * Reads the typed publish identity from a single non-autoloaded option
 * (written by a later configuration/REST stage). Only a valid, ready identity
 * makes hasIdentity() true; a missing, corrupt or not-yet-ready option safely
 * reads as "no identity", so the auto pipeline never starts an export before
 * the website + IPNS key pair is configured. No credentials are involved — the
 * gateway answers a boolean availability question and nothing else.
 */
final class WordPressIdentityGateway implements IdentityGateway
{
    public const OPTION_KEY = 'cast_publish_identity';

    public function __construct(private readonly OptionGateway $options)
    {
    }

    public function hasIdentity(): bool
    {
        $identity = $this->current();

        return $identity !== null && $identity->isReady();
    }

    public function current(): ?PublishIdentity
    {
        return PublishIdentity::fromOptionValue($this->options->get(self::OPTION_KEY, null));
    }
}
