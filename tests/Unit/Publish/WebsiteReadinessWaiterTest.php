<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use LumeWeb\Cast\Publish\PollPolicy;
use LumeWeb\Cast\Publish\Website;
use LumeWeb\Cast\Publish\WebsiteClientException;
use LumeWeb\Cast\Publish\WebsiteReadinessWaiter;
use PHPUnit\Framework\TestCase;

/**
 * WebsiteReadinessWaiter polls the website GET boundary until a deploy is
 * confirmed live: the site reports STATUS_LIVE and the active CID equals the
 * CID the publish just produced. Not-yet-served responses are re-polled with
 * bounded backoff; when the poll budget is exhausted the last observed site is
 * returned as a not-ready verdict so the caller resumes instead of falsely
 * advertising completion. A GET failure propagates so the caller decides.
 */
final class WebsiteReadinessWaiterTest extends TestCase
{
    private FakePublishClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FakePublishClock();
    }

    public function testReadyOnFirstPollWhenSiteIsLiveAndServesExpectedCid(): void
    {
        $websites = new FakeWebsiteClient();
        $websites->loopResponse = $this->liveSite('7', 'QmDeploy');
        $waiter = new WebsiteReadinessWaiter($websites, $this->clock);

        $readiness = $waiter->waitForCid('7', 'QmDeploy');

        self::assertTrue($readiness->isReady());
        self::assertSame('7', $readiness->websiteId);
        self::assertSame('QmDeploy', $readiness->expectedCid);
        self::assertSame(['7'], $websites->fetched);
        self::assertSame([], $this->clock->slept);
    }

    public function testPollsPendingUntilSiteServesExpectedCidSleepingWithBackoff(): void
    {
        $websites = new FakeWebsiteClient();
        $websites->getScript = [
            $this->pendingSite('7'),
            $this->pendingSite('7'),
            $this->liveSite('7', 'QmDeploy'),
        ];
        $waiter = new WebsiteReadinessWaiter($websites, $this->clock);

        $readiness = $waiter->waitForCid('7', 'QmDeploy');

        self::assertTrue($readiness->isReady());
        self::assertCount(3, $websites->fetched);
        self::assertSame([2, 3], $this->clock->slept);
    }

    public function testKeepsPollingWhenLiveSiteStillServesOldCid(): void
    {
        $websites = new FakeWebsiteClient();
        $websites->getScript = [
            $this->liveSite('7', 'QmOld'),
            $this->liveSite('7', 'QmDeploy'),
        ];
        $waiter = new WebsiteReadinessWaiter($websites, $this->clock);

        $readiness = $waiter->waitForCid('7', 'QmDeploy');

        self::assertTrue($readiness->isReady());
        self::assertSame([2], $this->clock->slept);
    }

    public function testReturnsNotReadyLastSiteWhenPollBudgetExhausted(): void
    {
        $websites = new FakeWebsiteClient();
        $websites->getScript = [];
        $websites->loopResponse = $this->pendingSite('7');
        $waiter = new WebsiteReadinessWaiter($websites, $this->clock, new PollPolicy(maxPolls: 3));

        $readiness = $waiter->waitForCid('7', 'QmDeploy');

        self::assertFalse($readiness->isReady());
        self::assertSame('7', $readiness->websiteId);
        self::assertSame('pending', $readiness->site->status);
        self::assertNull($readiness->site->activeCid);
        self::assertCount(3, $websites->fetched);
        self::assertSame([2, 3], $this->clock->slept);
    }

    public function testGetFailurePropagatesToCaller(): void
    {
        $websites = new FakeWebsiteClient();
        $websites->getError = 'website vanished mid-poll';
        $waiter = new WebsiteReadinessWaiter($websites, $this->clock);

        $this->expectException(WebsiteClientException::class);
        $this->expectExceptionMessage('website vanished mid-poll');

        $waiter->waitForCid('7', 'QmDeploy');
    }

    private function pendingSite(string $id): Website
    {
        return new Website($id, '', 'QmTarget', 'car', null, 'pending', null);
    }

    private function liveSite(string $id, string $activeCid): Website
    {
        return new Website($id, '', 'QmTarget', 'car', null, Website::STATUS_LIVE, $activeCid);
    }
}
