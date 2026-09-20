<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * Verdict from a readiness wait: whether the website is confirmed live and
 * serving the expected CID, plus the last observed site so the caller can
 * report (and resume from) the provisioning state that produced a timeout.
 */
final class WebsiteReadiness
{
    public function __construct(
        public readonly bool $ready,
        public readonly string $websiteId,
        public readonly string $expectedCid,
        public readonly Website $site,
    ) {
    }

    public function isReady(): bool
    {
        return $this->ready;
    }
}
