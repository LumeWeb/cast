<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * Polls the website GET boundary until a deploy is actually live: the site
 * reports STATUS_LIVE and its active CID equals the CID the publish just
 * produced. Sleeps between polls with bounded backoff so a slow portal never
 * blocks one tick for its full budget. When the budget is exhausted the last
 * observed site is returned as a not-ready verdict — the caller turns that into
 * a resumable state instead of falsely advertising completion. This is the
 * WaitForWebsiteStatus/active-CID check the pure orchestration runs after the
 * IPNS publish.
 */
final class WebsiteReadinessWaiter
{
    public function __construct(
        private readonly WebsiteClient $websites,
        private readonly PublishClock $clock,
        private readonly PollPolicy $policy = new PollPolicy(),
    ) {
    }

    public function waitForCid(string $websiteId, string $expectedCid): WebsiteReadiness
    {
        $polls = 0;
        do {
            $site = $this->websites->get($websiteId);
            ++$polls;
            $ready = $this->isReady($site, $expectedCid);
            if ($ready || $polls >= $this->policy->maxPolls()) {
                return new WebsiteReadiness($ready, $websiteId, $expectedCid, $site);
            }
            $this->clock->sleep($this->policy->delayFor($polls));
        } while (true);
    }

    private function isReady(Website $site, string $expectedCid): bool
    {
        return $site->status === Website::STATUS_LIVE && $site->activeCid === $expectedCid;
    }
}
