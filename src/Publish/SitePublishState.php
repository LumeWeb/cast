<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * What the site already owns on the portal side. Either value may still be
 * null mid-first-publish (website created but IPNS key not yet): the
 * orchestration reads this to decide create-vs-update and never creates a
 * second website or key for a site that already has one. The IPNS half carries
 * both the friendly key name and the numeric portal key id, so a real adapter
 * can resolve the name back to the id the publish call needs.
 */
final class SitePublishState
{
    public function __construct(
        public readonly ?string $websiteId = null,
        public readonly ?string $ipnsKey = null,
        public readonly ?string $ipnsKeyId = null,
    ) {
    }

    public function isNew(): bool
    {
        return $this->websiteId === null && $this->ipnsKey === null;
    }
}
