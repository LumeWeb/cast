<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use LumeWeb\Cast\Publish\Artifact;
use LumeWeb\Cast\Publish\PollPolicy;
use LumeWeb\Cast\Publish\PublishOutcome;
use LumeWeb\Cast\Publish\PublishService;
use LumeWeb\Cast\Publish\PublishStage;
use LumeWeb\Cast\Publish\UploadResult;
use LumeWeb\Cast\Publish\UploadRouter;
use LumeWeb\Cast\Publish\UploadStatus;
use LumeWeb\Cast\Publish\UploadWaiter;
use LumeWeb\Cast\Publish\Website;
use LumeWeb\Cast\Publish\WebsiteReadinessWaiter;
use PHPUnit\Framework\TestCase;

/**
 * The subsequent-publish + resume orchestration: once a site already owns a
 * website (and possibly an IPNS key) on the portal side, a new artifact must
 * republish the existing IPNS key and re-point the existing website target (to
 * the mutable IPNS name in ipns mode, to the CID in legacy ipfs mode). The
 * service must never invite a second website or a second key, and a resume
 * must pick up exactly where the earlier run stalled instead of re-creating
 * identity that already exists.
 */
final class PublishServiceSubsequentPublishTest extends TestCase
{
    private FakeUploadClient $uploads;
    private FakeWebsiteClient $websites;
    private FakeIpnsClient $ipns;
    private FakePublishRegistry $registry;
    private RecordingPublishListener $listener;
    private FakePublishClock $clock;
    private PublishService $service;

    protected function setUp(): void
    {
        $this->uploads = new FakeUploadClient([new UploadResult(UploadStatus::Completed, cid: 'QmHash')]);
        $this->websites = new FakeWebsiteClient();
        $this->ipns = new FakeIpnsClient();
        $this->registry = new FakePublishRegistry();
        $this->listener = new RecordingPublishListener();
        $this->clock = new FakePublishClock();
        $this->service = new PublishService(
            router: new UploadRouter(),
            uploads: new UploadWaiter($this->uploads, $this->clock),
            websites: $this->websites,
            ipns: $this->ipns,
            registry: $this->registry,
            readiness: new WebsiteReadinessWaiter($this->websites, $this->clock),
            listener: $this->listener,
        );
    }

    public function testSubsequentPublishUpdatesExistingWebsiteInsteadOfCreatingAnother(): void
    {
        $this->registry->seed(websiteId: 'website-7', ipnsKey: 'k1-example.com');

        $result = $this->service->publish($this->artifact());

        self::assertSame(PublishOutcome::Completed, $result->outcome);
        self::assertSame('QmHash', $result->cid);
        self::assertSame('website-7', $result->websiteId);
        self::assertSame('k1-example.com', $result->ipnsKey);

        // The existing website target is re-pointed at the new CID; none is created.
        self::assertSame([], $this->websites->created);
        self::assertSame([['website-7', 'QmHash', 'ipfs-dir']], $this->websites->updated);
    }

    public function testSubsequentPublishRepublishesExistingIpnsKeyWithoutCreatingAnother(): void
    {
        $this->registry->seed(websiteId: 'website-7', ipnsKey: 'k1-example.com');

        $this->service->publish($this->artifact());

        // The existing key is republished to the new CID; no second key is created.
        self::assertSame([], $this->ipns->createdKeys);
        self::assertSame([['k1-example.com', 'QmHash']], $this->ipns->published);
    }

    public function testSubsequentPublishStageSequenceSkipsCreationStages(): void
    {
        $this->registry->seed(websiteId: 'website-7', ipnsKey: 'k1-example.com');

        $this->service->publish($this->artifact());

        self::assertSame([
            PublishStage::Uploading,
            PublishStage::Polling,
            PublishStage::PublishingIpns,
            PublishStage::UpdatingWebsite,
            PublishStage::CheckingReadiness,
            PublishStage::Completed,
        ], $this->stageSequence());
    }

    public function testRepeatedCompletionNeverDuplicatesWebsiteOrKey(): void
    {
        $this->registry->seed(websiteId: 'website-7', ipnsKey: 'k1-example.com');
        // Every run (not just the first) uploads and resolves the same CID.
        $this->uploads->loopResponse = new UploadResult(UploadStatus::Completed, cid: 'QmHash');

        $this->service->publish($this->artifact());
        $this->service->publish($this->artifact());

        // Two runs re-point and republish, but still only one website and one key exist.
        self::assertSame([], $this->websites->created);
        self::assertSame([['website-7', 'QmHash', 'ipfs-dir'], ['website-7', 'QmHash', 'ipfs-dir']], $this->websites->updated);
        self::assertSame([], $this->ipns->createdKeys);
        self::assertSame([['k1-example.com', 'QmHash'], ['k1-example.com', 'QmHash']], $this->ipns->published);
        $state = $this->registry->current();
        self::assertNotNull($state);
        self::assertSame('website-7', $state->websiteId);
        self::assertSame('k1-example.com', $state->ipnsKey);
    }

    public function testResumeWithExistingWebsiteButMissingIpnsKeyCreatesKeyWithoutAnotherWebsite(): void
    {
        // An earlier first-publish stalled after websites.create succeeded but
        // before the IPNS key existed: identity (website) must not be redone.
        $this->registry->seed(websiteId: 'website-7');

        $result = $this->service->publish($this->artifact());

        self::assertSame(PublishOutcome::Completed, $result->outcome);
        self::assertSame('website-7', $result->websiteId);
        self::assertSame('k1-example.com', $result->ipnsKey);

        // The existing website is re-pointed (never re-created); the missing key is created once.
        self::assertSame([], $this->websites->created);
        self::assertSame([['website-7', 'QmHash', 'ipfs-dir']], $this->websites->updated);
        self::assertSame(['example.com'], $this->ipns->createdKeys);
        self::assertSame([['k1-example.com', 'QmHash']], $this->ipns->published);
        self::assertSame('k1-example.com', $this->registry->current()?->ipnsKey);
    }

    public function testResumeTargetUpdateFailurePreservesCidAndSkipsNothing(): void
    {
        $this->registry->seed(websiteId: 'website-7', ipnsKey: 'k1-example.com');
        $this->websites->updateError = 'update service down';

        $result = $this->service->publish($this->artifact());

        self::assertSame(PublishOutcome::Resumable, $result->outcome);
        self::assertSame('QmHash', $result->cid);
        self::assertSame(PublishStage::UpdatingWebsite, $result->resume?->stage);
        self::assertStringContainsString('update service down', (string) $result->message);
        // No website or key creation happened; the CID was already published to
        // the existing key (the IPNS half precedes the website step), then the
        // run stopped at the update.
        self::assertSame([], $this->websites->created);
        self::assertSame([], $this->ipns->createdKeys);
        self::assertSame([['k1-example.com', 'QmHash']], $this->ipns->published);
        $state = $this->registry->current();
        self::assertNotNull($state);
        self::assertSame('website-7', $state->websiteId);
        self::assertSame('k1-example.com', $state->ipnsKey);
    }

    public function testResumeIpnsKeyCreationFailureKeepsWebsiteAndCid(): void
    {
        $this->registry->seed(websiteId: 'website-7');
        $this->ipns->createError = 'ipns key service down';

        $result = $this->service->publish($this->artifact());

        self::assertSame(PublishOutcome::Resumable, $result->outcome);
        self::assertSame('QmHash', $result->cid);
        self::assertSame('website-7', $result->websiteId);
        self::assertNull($result->ipnsKey);
        self::assertSame(PublishStage::CreatingIpnsKey, $result->resume?->stage);
        // The key is created before the website update, so a key failure means
        // the re-point never happened and nothing was published.
        self::assertSame([], $this->websites->created);
        self::assertSame([], $this->websites->updated);
        self::assertSame([], $this->ipns->published);
        $state = $this->registry->current();
        self::assertNotNull($state);
        self::assertSame('website-7', $state->websiteId);
        self::assertNull($state->ipnsKey);
    }

    public function testResumeIpnsPublishFailureRepublishesNothingAndKeepsKey(): void
    {
        $this->registry->seed(websiteId: 'website-7', ipnsKey: 'k1-example.com');
        $this->ipns->publishError = 'ipns publish down';

        $result = $this->service->publish($this->artifact());

        self::assertSame(PublishOutcome::Resumable, $result->outcome);
        self::assertSame('QmHash', $result->cid);
        self::assertSame('website-7', $result->websiteId);
        self::assertSame('k1-example.com', $result->ipnsKey);
        self::assertSame(PublishStage::PublishingIpns, $result->resume?->stage);
        // The publish precedes the re-point, so a publish failure stalls
        // before the website target is touched.
        self::assertSame([], $this->websites->created);
        self::assertSame([], $this->websites->updated);
        self::assertSame([], $this->ipns->createdKeys);
        self::assertSame([], $this->ipns->published);
        $state = $this->registry->current();
        self::assertNotNull($state);
        self::assertSame('website-7', $state->websiteId);
        self::assertSame('k1-example.com', $state->ipnsKey);
    }

    public function testSubsequentPublishIpnsModeRepointsWebsiteAtIpnsName(): void
    {
        // In Cast's ipns default the re-point targets the mutable IPNS name
        // (stable per key), not the raw CID, so the site keeps following the
        // newest publication.
        $this->registry->seed(websiteId: 'website-7', ipnsKey: 'k1-example.com');
        // Readiness confirms the site serves the CID the name resolves to.
        $this->liveAt('website-7', 'QmHash');

        $result = $this->service->publish($this->artifact(targetType: 'ipns'));

        self::assertSame(PublishOutcome::Completed, $result->outcome);
        self::assertSame('website-7', $result->websiteId);
        self::assertSame('k1-example.com', $result->ipnsKey);
        self::assertSame([], $this->websites->created);
        self::assertSame([['website-7', 'k51-k1-example.com', 'ipns']], $this->websites->updated);
        self::assertSame([], $this->ipns->createdKeys);
        self::assertSame([['k1-example.com', 'QmHash']], $this->ipns->published);
    }

    public function testSubsequentPublishConfirmsReadinessForTheRePointedWebsiteBeforeCompleting(): void
    {
        $this->registry->seed(websiteId: 'website-7', ipnsKey: 'k1-example.com');

        $result = $this->service->publish($this->artifact());

        self::assertSame(PublishOutcome::Completed, $result->outcome);
        self::assertNotNull($result->readiness);
        self::assertTrue($result->readiness->isReady());
        self::assertSame('website-7', $result->readiness->websiteId);
        self::assertSame('QmHash', $result->readiness->site->activeCid);
        // The readiness waiter polled the existing website boundary, not a new one.
        self::assertSame(['website-7'], $this->websites->fetched);
    }

    /**
     * @return list<PublishStage>
     */
    private function stageSequence(): array
    {
        return array_map(static fn ($progress): PublishStage => $progress->stage, $this->listener->progress);
    }

    private function artifact(string $targetType = 'ipfs-dir'): Artifact
    {
        return new Artifact('/tmp/cast-export/run-xyz-456.zip', 'run-xyz-456.zip', $targetType, 'example.com', 4096);
    }

    /**
     * Seed the readiness waiter's first get() with a website that is live and
     * actively serving the given CID, so an ipns-mode re-point — whose website
     * target_hash is a name, not the CID — still confirms readiness.
     */
    private function liveAt(string $websiteId, string $cid): void
    {
        $this->websites->getScript = [
            new Website($websiteId, '', $cid, 'car', null, Website::STATUS_LIVE, $cid),
        ];
    }
}
