<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use InvalidArgumentException;
use LumeWeb\Cast\Export\PublishBoundaryResult;
use LumeWeb\Cast\Export\PublishBoundaryStatus;
use PHPUnit\Framework\TestCase;

/**
 * The persisted outcome of the publish pipeline boundary: whether the publish
 * completed, failed or stalled resumable, together with the CID/website/IPNS
 * identity produced so far and a plain-language message. Mirrors the other
 * per-stage result DTOs so ExportRun can carry it across WP-Cron requests and
 * rehydrate PipelineState without re-publishing.
 */
final class PublishBoundaryResultTest extends TestCase
{
    public function testCompletedFactoryCarriesTheFullIdentity(): void
    {
        $result = PublishBoundaryResult::completed('QmHash', 'website-1', 'k1-example.com');

        self::assertSame(PublishBoundaryStatus::Completed, $result->status);
        self::assertSame('QmHash', $result->cid);
        self::assertSame('website-1', $result->websiteId);
        self::assertSame('k1-example.com', $result->ipnsKey);
        self::assertNull($result->message);
        self::assertTrue($result->isSuccess());
    }

    public function testFailedFactoryCarriesTheReason(): void
    {
        $result = PublishBoundaryResult::failed('connection refused');

        self::assertSame(PublishBoundaryStatus::Failed, $result->status);
        self::assertNull($result->cid);
        self::assertNull($result->websiteId);
        self::assertNull($result->ipnsKey);
        self::assertSame('connection refused', $result->message);
        self::assertFalse($result->isSuccess());
    }

    public function testResumableFactoryPreservesIdentityProducedSoFar(): void
    {
        $result = PublishBoundaryResult::resumable('QmHash', 'website-1', null, 'IPNS key creation stalled');

        self::assertSame(PublishBoundaryStatus::Resumable, $result->status);
        self::assertSame('QmHash', $result->cid);
        self::assertSame('website-1', $result->websiteId);
        self::assertNull($result->ipnsKey);
        self::assertSame('IPNS key creation stalled', $result->message);
        self::assertFalse($result->isSuccess());
    }

    public function testCompletedResultRoundTripsThroughItsPersistedShape(): void
    {
        $result = PublishBoundaryResult::completed('QmHash', 'website-1', 'k1-example.com');

        $restored = PublishBoundaryResult::fromArray($result->toArray());

        self::assertSame(PublishBoundaryStatus::Completed, $restored->status);
        self::assertSame('QmHash', $restored->cid);
        self::assertSame('website-1', $restored->websiteId);
        self::assertSame('k1-example.com', $restored->ipnsKey);
        self::assertNull($restored->message);
        // round-trip is stable: persisting the restored result changes nothing.
        self::assertSame($result->toArray(), $restored->toArray());
    }

    public function testFailedAndResumableResultsRetainDetailsThroughRoundTrip(): void
    {
        $failed = PublishBoundaryResult::failed('connection refused');
        $restored = PublishBoundaryResult::fromArray($failed->toArray());
        self::assertSame(PublishBoundaryStatus::Failed, $restored->status);
        self::assertSame('connection refused', $restored->message);
        self::assertNull($restored->cid);
        self::assertNull($restored->websiteId);
        self::assertNull($restored->ipnsKey);
        self::assertSame($failed->toArray(), $restored->toArray());

        $resumable = PublishBoundaryResult::resumable('QmHash', 'website-1', 'k1-example.com', 'publish stalled');
        $restored = PublishBoundaryResult::fromArray($resumable->toArray());
        self::assertSame(PublishBoundaryStatus::Resumable, $restored->status);
        self::assertSame('QmHash', $restored->cid);
        self::assertSame('website-1', $restored->websiteId);
        self::assertSame('k1-example.com', $restored->ipnsKey);
        self::assertSame('publish stalled', $restored->message);
        self::assertSame($resumable->toArray(), $restored->toArray());
    }

    public function testFromArrayRejectsMalformedStatus(): void
    {
        $data = PublishBoundaryResult::completed('QmHash', 'website-1', 'k1-example.com')->toArray();
        $data['status'] = 'bogus';

        $this->expectException(InvalidArgumentException::class);
        PublishBoundaryResult::fromArray($data);
    }

    public function testFromArrayRejectsNonArrayData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PublishBoundaryResult::fromArray('nope');
    }
}
