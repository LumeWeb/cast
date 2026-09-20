<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use LumeWeb\Cast\Publish\Artifact;
use LumeWeb\Cast\Publish\PublishOutcome;
use LumeWeb\Cast\Publish\PublishService;
use LumeWeb\Cast\Publish\PublishStage;
use LumeWeb\Cast\Publish\UploadResult;
use LumeWeb\Cast\Publish\UploadRouter;
use LumeWeb\Cast\Publish\UploadStatus;
use LumeWeb\Cast\Publish\UploadWaiter;
use LumeWeb\Cast\Publish\PollPolicy;
use LumeWeb\Cast\Publish\Website;
use LumeWeb\Cast\Publish\WebsiteReadinessWaiter;
use PHPUnit\Framework\TestCase;

/**
 * The first-publish orchestration: upload the archive (archive=true + name),
 * wait for and interpret the terminal upload result, then reconcile the IPNS
 * half BEFORE the website (the key is created once and the CID published,
 * because an IPNS-targeted website requires a live publication), then create
 * the website (domain omitted). In ipns mode — Cast's new default — the
 * website is pointed at the mutable IPNS name; a legacy ipfs run stamps the
 * CID. Failure boundaries are covered here; the "never create a second
 * website/key" rules are covered by the subsequent-publish suite.
 */
final class PublishServiceFirstPublishTest extends TestCase
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
        $this->service = $this->makeService();
    }

    public function testFirstPublishUploadsWithArchiveAndNameThenCreatesWebsiteFromCidAndPublishesIpns(): void
    {
        $result = $this->service->publish($this->artifact());

        self::assertSame(PublishOutcome::Completed, $result->outcome);
        self::assertSame('QmHash', $result->cid);
        self::assertSame('website-1', $result->websiteId);
        self::assertSame('k1-example.com', $result->ipnsKey);

        // The artifact is uploaded with archive=true and the run's name.
        self::assertCount(1, $this->uploads->specs);
        self::assertSame('/tmp/cast-export/run-abc-123.zip', $this->uploads->specs[0]->artifactPath);
        self::assertSame('run-abc-123.zip', $this->uploads->specs[0]->name);
        self::assertTrue($this->uploads->specs[0]->archive);
        self::assertSame(2048, $this->uploads->specs[0]->sizeBytes);
        self::assertSame(\LumeWeb\Cast\Publish\UploadRoute::Post, $this->uploads->specs[0]->route);

        // The website is created only after the CID exists AND the IPNS key is
        // created + the CID published (an IPNS-targeted website needs the
        // publication first). In legacy ipfs mode the target stays the CID;
        // type comes from the artifact, label = site hostname, and no domain.
        self::assertCount(1, $this->websites->created);
        $create = $this->websites->created[0];
        self::assertSame('QmHash', $create->targetHash);
        self::assertSame('ipfs-dir', $create->targetType);
        self::assertSame('example.com', $create->label);
        self::assertNull($create->domain);

        // One IPNS key is created for the site, then the CID is published to it.
        self::assertSame(['example.com'], $this->ipns->createdKeys);
        self::assertSame([['k1-example.com', 'QmHash']], $this->ipns->published);

        $state = $this->registry->current();
        self::assertNotNull($state);
        self::assertSame('website-1', $state->websiteId);
        self::assertSame('k1-example.com', $state->ipnsKey);
    }

    public function testProgressEventsEmitTheFullStageSequenceWithoutLeakingCredentials(): void
    {
        $this->service->publish($this->artifact());

        $stages = array_map(static fn ($progress): PublishStage => $progress->stage, $this->listener->progress);
        self::assertSame([
            PublishStage::Uploading,
            PublishStage::Polling,
            // The IPNS half runs before the website: the key must exist and be
            // published before an IPNS-targeted website can be created.
            PublishStage::CreatingIpnsKey,
            PublishStage::PublishingIpns,
            PublishStage::CreatingWebsite,
            PublishStage::CheckingReadiness,
            PublishStage::Completed,
        ], $stages);

        foreach ($this->listener->progress as $progress) {
            self::assertNotSame('', $progress->message);
            self::assertStringNotContainsStringIgnoringCase('Bearer', $progress->message);
            self::assertStringNotContainsStringIgnoringCase('secret', $progress->message);
            self::assertStringNotContainsStringIgnoringCase('api-key', $progress->message);
            self::assertStringNotContainsStringIgnoringCase('token', $progress->message);
        }
    }

    public function testLargeArtifactIsRoutedToTusInTheUploadSpec(): void
    {
        $this->service->publish($this->artifact(sizeBytes: 200 * 1024 * 1024));

        self::assertSame(\LumeWeb\Cast\Publish\UploadRoute::Tus, $this->uploads->specs[0]->route);
    }

    public function testUploadFailurePreventsAnyWebsiteOrIpnsWork(): void
    {
        $this->uploads->uploadError = 'connection refused';

        $result = $this->service->publish($this->artifact());

        self::assertSame(PublishOutcome::Failed, $result->outcome);
        self::assertNull($result->cid);
        self::assertStringContainsString('connection refused', (string) $result->message);
        self::assertSame([], $this->websites->created);
        self::assertSame([], $this->ipns->createdKeys);
        self::assertSame([], $this->ipns->published);
        self::assertNull($this->registry->current());
        self::assertSame([PublishStage::Uploading, PublishStage::Failed], $this->stageSequence());
    }

    public function testFailedUploadResultPreventsWebsiteCreation(): void
    {
        $this->uploads->pollScript = [new UploadResult(UploadStatus::Failed, message: 'Server rejected the archive')];

        $result = $this->service->publish($this->artifact());

        self::assertSame(PublishOutcome::Failed, $result->outcome);
        self::assertNull($result->cid);
        self::assertStringContainsString('Server rejected the archive', (string) $result->message);
        self::assertSame([], $this->websites->created);
        self::assertSame([], $this->ipns->createdKeys);
        self::assertNull($this->registry->current());
    }

    public function testCompletedWithoutCidIsTreatedAsFailure(): void
    {
        $this->uploads->pollScript = [new UploadResult(UploadStatus::Completed)];

        $result = $this->service->publish($this->artifact());

        self::assertSame(PublishOutcome::Failed, $result->outcome);
        self::assertSame([], $this->websites->created);
        self::assertNull($this->registry->current());
    }

    public function testPollingTimeoutFailsWithoutCreatingAWebsite(): void
    {
        $pending = new UploadResult(UploadStatus::Pending);
        $this->uploads->pollScript = [];
        $this->uploads->loopResponse = $pending;
        $this->service = $this->makeService(new PollPolicy(maxPolls: 2));

        $result = $this->service->publish($this->artifact());

        self::assertSame(PublishOutcome::Failed, $result->outcome);
        self::assertStringContainsString('terminal', (string) $result->message);
        self::assertSame([], $this->websites->created);
        self::assertNull($this->registry->current());
    }

    public function testWebsiteCreationFailurePreservesCidAndReturnsResumableState(): void
    {
        $this->websites->createError = 'website service down';

        $result = $this->service->publish($this->artifact());

        self::assertSame(PublishOutcome::Resumable, $result->outcome);
        self::assertSame('QmHash', $result->cid);
        self::assertNull($result->websiteId);
        self::assertSame('k1-example.com', $result->ipnsKey);
        self::assertNotNull($result->resume);
        self::assertSame('QmHash', $result->resume->cid);
        self::assertSame('id-1', $result->resume->uploadIdentifier->value);
        self::assertSame(PublishStage::CreatingWebsite, $result->resume->stage);
        self::assertStringContainsString('website service down', (string) $result->message);
        // The IPNS key + publication were made before the website step, so the
        // retry never re-creates the key; it only needs to (re)create the
        // website against the already-published name.
        self::assertSame(['example.com'], $this->ipns->createdKeys);
        self::assertSame([['k1-example.com', 'QmHash']], $this->ipns->published);
        $state = $this->registry->current();
        self::assertNotNull($state);
        self::assertNull($state->websiteId);
        self::assertSame('k1-example.com', $state->ipnsKey);
        self::assertSame(
            [PublishStage::Uploading, PublishStage::Polling, PublishStage::CreatingIpnsKey, PublishStage::PublishingIpns, PublishStage::CreatingWebsite, PublishStage::Resumable],
            $this->stageSequence(),
        );
    }

    public function testIpnsKeyFailurePreventsTheWebsite(): void
    {
        // The key is created before the website (the website target needs the
        // publication), so a key failure means no website is ever attempted.
        $this->ipns->createError = 'ipns key service down';

        $result = $this->service->publish($this->artifact());

        self::assertSame(PublishOutcome::Resumable, $result->outcome);
        self::assertSame('QmHash', $result->cid);
        self::assertNull($result->websiteId);
        self::assertNull($result->ipnsKey);
        self::assertNotNull($result->resume);
        self::assertSame(PublishStage::CreatingIpnsKey, $result->resume->stage);
        self::assertStringContainsString('ipns key service down', (string) $result->message);
        // Nothing was created or recorded; nothing was published.
        self::assertSame([], $this->websites->created);
        self::assertSame([], $this->ipns->published);
        self::assertNull($this->registry->current());
        self::assertSame([PublishStage::Uploading, PublishStage::Polling, PublishStage::CreatingIpnsKey, PublishStage::Resumable], $this->stageSequence());
    }

    public function testIpnsPublishFailureKeepsKeyButPreventsTheWebsite(): void
    {
        // The CID is published before the website exists, so a publish failure
        // stalls with the key recorded but no website attempted.
        $this->ipns->publishError = 'ipns publish down';

        $result = $this->service->publish($this->artifact());

        self::assertSame(PublishOutcome::Resumable, $result->outcome);
        self::assertSame('QmHash', $result->cid);
        self::assertNull($result->websiteId);
        self::assertSame('k1-example.com', $result->ipnsKey);
        self::assertNotNull($result->resume);
        self::assertSame(PublishStage::PublishingIpns, $result->resume->stage);
        self::assertStringContainsString('ipns publish down', (string) $result->message);
        // The key was created and recorded; the publication did not land; the
        // website was never attempted.
        self::assertSame([], $this->websites->created);
        self::assertSame([], $this->ipns->published);
        $state = $this->registry->current();
        self::assertNotNull($state);
        self::assertNull($state->websiteId);
        self::assertSame('k1-example.com', $state->ipnsKey);
    }

    public function testCompletedPublishConfirmsReadinessAndCarriesTheVerdict(): void
    {
        $result = $this->service->publish($this->artifact());

        self::assertSame(PublishOutcome::Completed, $result->outcome);
        self::assertNotNull($result->readiness);
        self::assertTrue($result->readiness->isReady());
        self::assertSame('website-1', $result->readiness->websiteId);
        self::assertSame('QmHash', $result->readiness->expectedCid);
        self::assertSame(Website::STATUS_LIVE, $result->readiness->site->status);
        self::assertSame('QmHash', $result->readiness->site->activeCid);
        // The readiness waiter polled the website boundary to confirm the deploy.
        self::assertContains('website-1', $this->websites->fetched);
    }

    public function testFirstPublishIpnsDefaultCreatesWebsitePointingAtIpnsName(): void
    {
        // New runs default to ipns mode, so the very first publish
        // targets the mutable IPNS name (which the publish step just made
        // live), not the raw CID. The site serves the CID the name resolves to.
        $this->liveAt('website-1', 'QmHash');

        $result = $this->service->publish($this->artifact(targetType: 'ipns'));

        self::assertSame(PublishOutcome::Completed, $result->outcome);
        self::assertSame('QmHash', $result->cid);
        self::assertSame('k1-example.com', $result->ipnsKey);

        // Order: key -> publish -> website create (an IPNS-targeted website
        // needs the publication to exist first).
        self::assertSame(['example.com'], $this->ipns->createdKeys);
        self::assertSame([['k1-example.com', 'QmHash']], $this->ipns->published);

        self::assertCount(1, $this->websites->created);
        $create = $this->websites->created[0];
        self::assertSame('k51-k1-example.com', $create->targetHash);
        self::assertSame('ipns', $create->targetType);
        self::assertSame('example.com', $create->label);
        self::assertNull($create->domain);

        self::assertSame('website-1', $result->websiteId);
        $state = $this->registry->current();
        self::assertNotNull($state);
        self::assertSame('website-1', $state->websiteId);
        self::assertSame('k1-example.com', $state->ipnsKey);
    }

    public function testFirstPublishReorderEmitsKeyThenPublishThenCreate(): void
    {
        // The publish boundary must run the IPNS steps strictly before the
        // website create/update — the portal rejects an IPNS-targeted website
        // whose name has no publication (IPNS_KEY_NOT_FOUND).
        $this->liveAt('website-1', 'QmHash');

        $result = $this->service->publish($this->artifact(targetType: 'ipns'));

        self::assertSame(PublishOutcome::Completed, $result->outcome);

        self::assertSame([
            PublishStage::Uploading,
            PublishStage::Polling,
            PublishStage::CreatingIpnsKey,
            PublishStage::PublishingIpns,
            PublishStage::CreatingWebsite,
            PublishStage::CheckingReadiness,
            PublishStage::Completed,
        ], $this->stageSequence());
    }

    public function testReadinessTimeoutReturnsResumableWithoutFalseCompletion(): void
    {
        $pending = new Website('website-1', '', 'QmHash', 'ipfs-dir', null, 'pending', null);
        $this->websites->loopResponse = $pending;
        $this->service = $this->makeService(new PollPolicy(maxPolls: 2));

        $result = $this->service->publish($this->artifact());

        self::assertSame(PublishOutcome::Resumable, $result->outcome);
        self::assertSame('QmHash', $result->cid);
        self::assertSame('website-1', $result->websiteId);
        self::assertSame('k1-example.com', $result->ipnsKey);
        self::assertNotNull($result->resume);
        self::assertSame(PublishStage::CheckingReadiness, $result->resume->stage);
        self::assertNotNull($result->readiness);
        self::assertFalse($result->readiness->isReady());
        self::assertSame('pending', $result->readiness->site->status);
        self::assertStringContainsStringIgnoringCase('readiness', (string) $result->message);
        // Identity already owned survives so a retry resumes the wait instead of
        // re-creating the website or key.
        $state = $this->registry->current();
        self::assertNotNull($state);
        self::assertSame('website-1', $state->websiteId);
        self::assertSame('k1-example.com', $state->ipnsKey);
        self::assertSame([
            PublishStage::Uploading,
            PublishStage::Polling,
            PublishStage::CreatingIpnsKey,
            PublishStage::PublishingIpns,
            PublishStage::CreatingWebsite,
            PublishStage::CheckingReadiness,
            PublishStage::Resumable,
        ], $this->stageSequence());
    }

    public function testReadinessProbeFailureReturnsResumableInsteadOfFalsifyingCompletion(): void
    {
        $this->websites->getError = 'website status read failed mid-wait';

        $result = $this->service->publish($this->artifact());

        self::assertSame(PublishOutcome::Resumable, $result->outcome);
        self::assertSame('QmHash', $result->cid);
        self::assertSame('website-1', $result->websiteId);
        self::assertSame('k1-example.com', $result->ipnsKey);
        self::assertSame(PublishStage::CheckingReadiness, $result->resume?->stage);
        self::assertStringContainsString('website status read failed mid-wait', (string) $result->message);
    }

    private function makeService(?PollPolicy $policy = null): PublishService
    {
        $policy ??= new PollPolicy();

        return new PublishService(
            router: new UploadRouter(),
            uploads: new UploadWaiter($this->uploads, $this->clock, $policy),
            websites: $this->websites,
            ipns: $this->ipns,
            registry: $this->registry,
            readiness: new WebsiteReadinessWaiter($this->websites, $this->clock, $policy),
            listener: $this->listener,
        );
    }

    private function artifact(int $sizeBytes = 2048, string $name = 'run-abc-123.zip', string $label = 'example.com', string $targetType = 'ipfs-dir'): Artifact
    {
        return new Artifact('/tmp/cast-export/' . $name, $name, $targetType, $label, $sizeBytes);
    }

    /**
     * Seed the readiness waiter's first get() with a website that is live and
     * actively serving the given CID, so an ipns-mode publish — whose website
     * target_hash is a name, not the CID — still confirms readiness.
     */
    private function liveAt(string $websiteId, string $cid): void
    {
        $this->websites->getScript = [
            new Website($websiteId, '', $cid, 'car', null, Website::STATUS_LIVE, $cid),
        ];
    }

    /**
     * @return list<PublishStage>
     */
    private function stageSequence(): array
    {
        return array_map(static fn ($progress): PublishStage => $progress->stage, $this->listener->progress);
    }
}
