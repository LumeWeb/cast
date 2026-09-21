<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * A portal website as returned by the create/update/get surface: its id, label,
 * the CID-backed target it currently points at, the optional bound domain
 * (never present on first publish — the domain binds after the website exists),
 * and the lifecycle state read back by get(): the current status and the CID
 * the site actually serves. Status and active CID are what readiness polling
 * inspects to confirm a deploy is live. STATUS_LIVE is the status value the
 * portal reports once a website is up; anything else is still provisioning.
 */
final class Website
{
    public const STATUS_LIVE = 'active';

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $targetHash,
        public readonly string $targetType,
        public readonly ?string $domain = null,
        public readonly string $status = '',
        public readonly ?string $activeCid = null,
    ) {
    }
}
