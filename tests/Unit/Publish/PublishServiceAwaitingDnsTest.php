<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use LumeWeb\Cast\Publish\Artifact;
use LumeWeb\Cast\Publish\PublishDestination;
use LumeWeb\Cast\Publish\PublishOutcome;
use LumeWeb\Cast\Publish\PublishProgress;
use LumeWeb\Cast\Publish\PublishService;
use LumeWeb\Cast\Publish\UploadResult;
use LumeWeb\Cast\Publish\UploadRouter;
use LumeWeb\Cast\Publish\UploadStatus;
use LumeWeb\Cast\Publish\UploadWaiter;
use LumeWeb\Cast\Publish\Website;
use LumeWeb\Cast\Publish\WebsiteReadinessWaiter;
use PHPUnit\Framework\TestCase;

/**
 * The custom-domain pause: a FIRST publish whose confirmed destination is a
 * custom domain creates the website after the upload and then PAUSES at an
 * explicit awaiting-DNS boundary — the CID, the IPNS key and the created
 * website id are all preserved, and the readiness poll is never started (the
 * site cannot serve until the domain's DNS points at it). Platform and
 * existing destinations are unchanged: they proceed straight to the
 * readiness check. A LATER publish (the website identity already recorded)
 * never re-pauses: it re-points and reads readiness like any re-publish.
 */
final class PublishServiceAwaitingDnsTest extends TestCase
{
    private FakeUploadClient $uploads;
    private FakeWebsiteClient $websites;
    private FakeIpnsClient $ipns;
    private FakePublishRegistry $registry;
    private RecordingPublishListener $listener;
    private FakePublishClock $clock;

    protected function setUp(): void
    {
        $this->uploads = new FakeUploadClient([new UploadResult(UploadStatus::Completed, cid: 'QmHash')]);
        $this->websites = new FakeWebsiteClient();
        $this->ipns = new FakeIpnsClient();
        $this->registry = new FakePublishRegistry();
        $this->listener = new RecordingPublishListener();
        $this->clock = new FakePublishClock();
    }

    public function testCustomFirstPublishPausesAwaitingDnsPreservingCidIpnsAndWebsite(): void
    {
        $destination = PublishDestination::custom('shop.example.com', 'icann', false);

        $result = $this->makeService()->publish($this->artifact(), $destination);

        // The explicit awaiting-DNS verdict — never a generic resumable
        // failure and never a completion: the domain is not serving yet.
        self::assertSame(PublishOutcome::AwaitingDns, $result->outcome);
        self::assertSame('QmHash', $result->cid, 'the uploaded CID is preserved');
        self::assertSame('k1-aovvgar1.build.pinned.site', $result->ipnsKey, 'the IPNS key is preserved');
        self::assertNotNull($result->websiteId, 'the created website id is preserved');
        self::assertStringContainsString('shop.example.com', (string) $result->message);
        // The website was created from the confirmed destination and its
        // identity recorded — a resume re-points it, it never re-creates.
        self::assertCount(1, $this->websites->created);
        self::assertSame('shop.example.com', $this->websites->created[0]->domain);
        self::assertSame([], $this->websites->updated, 'a paused run never re-points');
        $state = $this->registry->current();
        self::assertNotNull($state);
        self::assertNotNull($state->websiteId);
        self::assertSame($state->ipnsKey, $result->ipnsKey);
        // The readiness boundary is never entered: the site cannot confirm a
        // CID before the domain DNS connects.
        $stages = array_map(
            static fn (PublishProgress $progress): string => $progress->stage->value,
            $this->listener->progress,
        );
        self::assertNotContains('checking_readiness', $stages);
        self::assertContains('awaiting_dns', $stages);
    }

    public function testCustomManagedDnsFirstPublishAlsoPausesAwaitingDns(): void
    {
        // The managed-DNS custom branch pauses too: even platform-hosted DNS
        // needs the verification pass before the domain serves.
        $destination = PublishDestination::custom('shop.example.com', 'icann', true);

        $result = $this->makeService()->publish($this->artifact(), $destination);

        self::assertSame(PublishOutcome::AwaitingDns, $result->outcome);
        self::assertSame('QmHash', $result->cid);
        self::assertNotNull($result->websiteId);
    }

    public function testPlatformFirstPublishStillProceedsToReadiness(): void
    {
        $destination = PublishDestination::platformGenerated('pinned.site', 'icann');

        $this->servesCid('website-1');
        $result = $this->makeService()->publish($this->artifact(), $destination);

        self::assertSame(PublishOutcome::Completed, $result->outcome);
        self::assertSame('website-1', $result->websiteId);
    }

    public function testExistingFirstPublishStillProceedsToReadiness(): void
    {
        $destination = PublishDestination::existing('42');
        $linker = new FakeWorkspaceLinker();

        $this->servesCid('42');
        $result = $this->makeService($linker, static fn (): string => '7')->publish($this->artifact(), $destination);

        self::assertSame(PublishOutcome::Completed, $result->outcome);
        self::assertSame('42', $result->websiteId);
    }

    public function testCustomSecondPublishDoesNotRePauseAndCompletes(): void
    {
        // The website identity is already recorded (the first publish created
        // it and paused): a later publish re-points the existing website and
        // proceeds to readiness — it never parks as awaiting-DNS again.
        $this->registry->recordWebsite('website-9', 'shop.example.com');
        $destination = PublishDestination::custom('shop.example.com', 'icann', false);

        $this->servesCid('website-9');
        $result = $this->makeService()->publish($this->artifact(), $destination);

        self::assertSame(PublishOutcome::Completed, $result->outcome);
        self::assertSame('website-9', $result->websiteId);
        self::assertSame(0, count($this->websites->created), 'a later publish never creates a second website');
        self::assertNotNull($this->websites->updated[0] ?? null, 'a later publish re-points the recorded website');
    }

    private function servesCid(string $websiteId): void
    {
        $this->websites->loopResponse = new Website($websiteId, '', 'QmHash', 'car', null, Website::STATUS_LIVE, 'QmHash');
    }

    private function artifact(): Artifact
    {
        return new Artifact(
            '/tmp/cast-export/run-abc-123.zip',
            'run-abc-123.zip',
            'ipns',
            'aovvgar1.build.pinned.site',
            2048,
        );
    }

    private function makeService(?FakeWorkspaceLinker $workspaces = null, ?\Closure $workspaceIdProvider = null): PublishService
    {
        return new PublishService(
            router: new UploadRouter(),
            uploads: new UploadWaiter($this->uploads, $this->clock),
            websites: $this->websites,
            ipns: $this->ipns,
            registry: $this->registry,
            readiness: new WebsiteReadinessWaiter($this->websites, $this->clock),
            listener: $this->listener,
            workspaces: $workspaces,
            workspaceIdProvider: $workspaceIdProvider,
        );
    }
}
