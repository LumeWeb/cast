<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\PackResult;
use LumeWeb\Cast\Export\PackStatus;
use LumeWeb\Cast\Export\PipelineStageKey;
use LumeWeb\Cast\Export\PipelineState;
use LumeWeb\Cast\Export\ProbeResult;
use LumeWeb\Cast\Export\PublishBoundaryStatus;
use LumeWeb\Cast\Export\PublishStage;
use LumeWeb\Cast\Publish\PollPolicy;
use LumeWeb\Cast\Publish\PublishService;
use LumeWeb\Cast\Publish\UploadResult;
use LumeWeb\Cast\Publish\UploadRouter;
use LumeWeb\Cast\Publish\UploadStatus;
use LumeWeb\Cast\Publish\UploadWaiter;
use LumeWeb\Cast\Publish\Website;
use LumeWeb\Cast\Publish\WebsiteReadinessWaiter;
use LumeWeb\Cast\Tests\Unit\Publish\FakeIpnsClient;
use LumeWeb\Cast\Tests\Unit\Publish\FakePublishClock;
use LumeWeb\Cast\Tests\Unit\Publish\FakePublishRegistry;
use LumeWeb\Cast\Tests\Unit\Publish\FakeUploadClient;
use LumeWeb\Cast\Tests\Unit\Publish\FakeWebsiteClient;
use LumeWeb\Cast\Tests\Unit\Publish\RecordingPublishListener;
use PHPUnit\Framework\TestCase;

/**
 * The publish pipeline boundary: the thinnest possible pipeline stage over
 * the existing {@see PublishService} — it turns the packed artifact the state
 * rehydrated (zip path + file size) plus the run's target type/label into an
 * {@see Artifact}, drives one publish through the service, and reports the
 * outcome as a bounded {@see StageResult} while recording a persisted
 * {@see PublishBoundaryResult} onto the shared state. The stage itself never
 * loads WordPress (fakes stand in for the clients) and never touches the run
 * aggregate. A publish failure — Failed or Resumable — surfaces as a stage
 * failure so the tick runner retries it; the durable PublishRegistry the
 * service reads makes a retry resume from exactly where the publish stalled.
 */
final class PublishStageTest extends TestCase
{
    private FakeUploadClient $uploads;
    private FakeWebsiteClient $websites;
    private FakeIpnsClient $ipns;
    private FakePublishRegistry $registry;
    private FakePublishClock $clock;
    private RecordingPublishListener $listener;
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
            uploads: new UploadWaiter($this->uploads, $this->clock, new PollPolicy()),
            websites: $this->websites,
            ipns: $this->ipns,
            registry: $this->registry,
            readiness: new WebsiteReadinessWaiter($this->websites, $this->clock, new PollPolicy()),
            listener: $this->listener,
        );
    }

    public function testKeyIsPublish(): void
    {
        $stage = new PublishStage($this->service, new PipelineState(), 'ipfs-dir', 'example.com');

        self::assertSame(PipelineStageKey::Publish, $stage->key());
    }

    public function testCompletedPublishReturnsDoneAndRecordsCompletedBoundaryResult(): void
    {
        $state = $this->stateWithPack();
        $stage = new PublishStage($this->service, $state, 'ipfs-dir', 'example.com');

        $result = $stage->execute('');

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertFalse($result->cancelled);
        self::assertSame('', $result->cursor);
        self::assertSame(0, $result->progress);

        // The artifact was routed to the PublishService with the packed zip:
        // archive name from the file, target type + label from the run.
        self::assertCount(1, $this->uploads->specs);
        self::assertSame('run-1.zip', $this->uploads->specs[0]->name);
        self::assertTrue($this->uploads->specs[0]->archive);

        // The completed identity is recorded onto the shared state.
        self::assertNotNull($state->publish);
        self::assertSame(PublishBoundaryStatus::Completed, $state->publish->status);
        self::assertSame('QmHash', $state->publish->cid);
        self::assertSame('website-1', $state->publish->websiteId);
        self::assertSame('k1-example.com', $state->publish->ipnsKey);
    }

    public function testFailedPublishReturnsAFailureAndRecordsFailedBoundaryResult(): void
    {
        $this->uploads->uploadError = 'connection refused';
        $state = $this->stateWithPack();
        $stage = new PublishStage($this->service, $state, 'ipfs-dir', 'example.com');

        $result = $stage->execute('');

        self::assertFalse($result->done);
        self::assertNotNull($result->failure);
        self::assertStringContainsString('connection refused', $result->failure);

        self::assertNotNull($state->publish);
        self::assertSame(PublishBoundaryStatus::Failed, $state->publish->status);
        self::assertNull($state->publish->cid);
        self::assertStringContainsString('connection refused', (string) $state->publish->message);
    }

    public function testResumablePublishFailurePreservesIdentityAndRecordsResumableBoundaryResult(): void
    {
        // The website already exists (identity durable) and website update
        // stalls: the publish returns resumable having preserved the CID.
        $this->registry->seed(websiteId: 'website-1', ipnsKey: 'k1-example.com');
        $this->websites->updateError = 'target update timed out';
        $state = $this->stateWithPack();
        $stage = new PublishStage($this->service, $state, 'ipfs-dir', 'example.com');

        $result = $stage->execute('');

        self::assertFalse($result->done);
        self::assertNotNull($result->failure);
        self::assertStringContainsString('target update timed out', $result->failure);

        // The CID and any identity already owned are preserved for the retry.
        self::assertNotNull($state->publish);
        self::assertSame(PublishBoundaryStatus::Resumable, $state->publish->status);
        self::assertSame('QmHash', $state->publish->cid);
        self::assertSame('website-1', $state->publish->websiteId);
        self::assertSame('k1-example.com', $state->publish->ipnsKey);
    }

    public function testReadinessTimeoutMapsToAResumableBoundaryResultWithoutFalsifyingCompletion(): void
    {
        // The publish flow finishes, but the website never reports live: the
        // boundary must stay resumable (never completed) so the tick runner
        // retries instead of advertising a publish that is not serving the CID.
        $this->websites->loopResponse = new Website('website-1', '', 'QmHash', 'ipfs-dir', null, 'pending', null);
        $state = $this->stateWithPack();
        $stage = new PublishStage($this->service, $state, 'ipfs-dir', 'example.com');

        $result = $stage->execute('');

        self::assertFalse($result->done);
        self::assertNotNull($result->failure);
        self::assertStringContainsStringIgnoringCase('readiness', $result->failure);

        // The preserved identity is recorded, proving the retry resumes the
        // readiness wait instead of re-creating the website or key.
        self::assertNotNull($state->publish);
        self::assertSame(PublishBoundaryStatus::Resumable, $state->publish->status);
        self::assertSame('QmHash', $state->publish->cid);
        self::assertSame('website-1', $state->publish->websiteId);
        self::assertSame('k1-example.com', $state->publish->ipnsKey);
    }

    public function testRequiresASuccessfulProbeFirst(): void
    {
        $stage = new PublishStage($this->service, new PipelineState(), 'ipfs-dir', 'example.com');

        $result = $stage->execute('');

        self::assertFalse($result->done);
        self::assertNotNull($result->failure);
        self::assertStringContainsString('probe', $result->failure);
        self::assertCount(0, $this->uploads->specs);
    }

    public function testRequiresASuccessfulPackFirst(): void
    {
        $state = new PipelineState();
        $state->probe = new ProbeResult(
            Origin::fromParts('https', 'blog.example.test', null),
            'https://blog.example.test/',
            1024,
            1,
        );
        $stage = new PublishStage($this->service, $state, 'ipfs-dir', 'example.com');

        $result = $stage->execute('');

        self::assertFalse($result->done);
        self::assertNotNull($result->failure);
        self::assertStringContainsString('pack', $result->failure);
        self::assertCount(0, $this->uploads->specs);
    }

    public function testMissingArtifactZipFailsBeforeTouchingTheService(): void
    {
        $state = $this->stateWithPack(missingZip: true);
        $stage = new PublishStage($this->service, $state, 'ipfs-dir', 'example.com');

        $result = $stage->execute('');

        self::assertFalse($result->done);
        self::assertNotNull($result->failure);
        self::assertStringContainsString('Artifact ZIP', $result->failure);
        self::assertCount(0, $this->uploads->specs);

        // A missing artifact is recorded as a failed boundary result.
        self::assertNotNull($state->publish);
        self::assertSame(PublishBoundaryStatus::Failed, $state->publish->status);
    }

    private function stateWithPack(bool $missingZip = false): PipelineState
    {
        $state = new PipelineState();
        $state->probe = new ProbeResult(
            Origin::fromParts('https', 'blog.example.test', null),
            'https://blog.example.test/',
            1024,
            1,
        );

        // The real pack stage writes `{runId}.zip` into the exports sibling, so
        // the publish stage derives the upload artifact name from that basename.
        $zipPath = sys_get_temp_dir() . '/cast-export/run-1.zip';
        if (!$missingZip) {
            if (!is_dir(dirname($zipPath))) {
                mkdir(dirname($zipPath), 0777, true);
            }
            file_put_contents($zipPath, str_repeat('x', 2048));
        } elseif (is_file($zipPath)) {
            unlink($zipPath);
        }

        $state->pack = new PackResult(
            PackStatus::Completed,
            42,
            0,
            2048,
            $zipPath,
            sys_get_temp_dir() . '/cast-export/run-1-manifest.json',
        );

        return $state;
    }
}
