<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * In-memory IdentityGateway for tests and single-process runtimes.
 *
 * Supports both a plain boolean and a typed {@see PublishIdentity}: once a
 * typed current identity is set, its readiness drives the boolean availability
 * decision; otherwise the boolean constructor/flag carries the answer.
 */
final class InMemoryIdentityGateway implements IdentityGateway
{
    private bool $identity = false;

    private ?PublishIdentity $current = null;

    public function __construct(bool $identity = false)
    {
        $this->identity = $identity;
    }

    public function hasIdentity(): bool
    {
        if ($this->current !== null) {
            return $this->current->isReady();
        }

        return $this->identity;
    }

    public function setIdentity(bool $present): void
    {
        $this->identity = $present;
    }

    public function current(): ?PublishIdentity
    {
        return $this->current;
    }

    public function setCurrentIdentity(?PublishIdentity $identity): void
    {
        $this->current = $identity;
        $this->identity = $identity !== null && $identity->isReady();
    }
}
