<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * Durable record of what the site already owns on the portal side. It is
 * written incrementally — the IPNS key half first, the website id + name
 * second, matching the publish() ordering (the key must exist and be published
 * before an IPNS-targeted website can be created) — so a crash or failure
 * between the two never makes the next publish re-create what already exists.
 * The names/ids retained here are exactly the full, non-credential identity
 * {@see \LumeWeb\Cast\Jobs\PublishIdentity} exposes, so the concrete registry
 * can persist it in the option the identity gateway reads. A fake backs it in
 * unit tests; the WordPress adapter is a single option aggregate mirroring the
 * onboarding pattern.
 */
interface PublishRegistry
{
    public function current(): ?SitePublishState;

    public function recordWebsite(string $websiteId, string $websiteName): void;

    public function recordIpnsKey(string $ipnsKeyName, ?string $ipnsKeyId): void;
}
