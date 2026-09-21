<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use LumeWeb\Cast\Publish\PublishRegistry;
use LumeWeb\Cast\Publish\SitePublishState;

/**
 * In-memory publish identity store. seed() lets a test start from a partially
 * completed publish (website already created, IPNS key still missing) to prove
 * a resumed flow never duplicates the website.
 */
final class FakePublishRegistry implements PublishRegistry
{
    private ?string $websiteId = null;

    private ?string $ipnsKey = null;

    private ?string $ipnsKeyId = null;

    public function seed(?string $websiteId = null, ?string $ipnsKey = null, ?string $ipnsKeyId = null): void
    {
        $this->websiteId = $websiteId;
        $this->ipnsKey = $ipnsKey;
        $this->ipnsKeyId = $ipnsKeyId;
    }

    public function current(): ?SitePublishState
    {
        if ($this->websiteId === null && $this->ipnsKey === null) {
            return null;
        }

        return new SitePublishState($this->websiteId, $this->ipnsKey, $this->ipnsKeyId);
    }

    public function recordWebsite(string $websiteId, string $websiteName): void
    {
        $this->websiteId = $websiteId;
    }

    public function recordIpnsKey(string $ipnsKeyName, ?string $ipnsKeyId): void
    {
        $this->ipnsKey = $ipnsKeyName;
        $this->ipnsKeyId = $ipnsKeyId;
    }
}
