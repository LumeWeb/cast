<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * Answers whether a publish destination identity (website + IPNS key) exists
 * yet. The auto pipeline must never start an export before the first identity
 * is configured; manual first publish stays explicit and is the only path that
 * may run earlier. No credentials ever flow through this adapter — it only
 * answers a boolean availability question.
 */
interface IdentityGateway
{
    public function hasIdentity(): bool;

    /**
     * The resolved publish identity, or null when none exists yet.
     *
     * Only identifiers (website/IPNS ids + names) are reachable through the
     * returned value; credentials never flow through this adapter.
     */
    public function current(): ?PublishIdentity;
}
