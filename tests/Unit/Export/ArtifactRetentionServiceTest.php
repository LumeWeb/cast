<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\ArtifactRetentionService;
use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\InMemoryRunRepository;
use LumeWeb\Cast\Export\PackResult;
use LumeWeb\Cast\Export\PackStatus;
use LumeWeb\Cast\Export\RetentionPolicy;
use LumeWeb\Cast\Export\RunStatus;
use PHPUnit\Framework\TestCase;

/**
 * Pure artifact retention service: given the store abstraction and the run
 * repository, it collects expired, unprotected artifact ZIPs while always
 * protecting the shared manifest (never enumerated), the latest
 * artifact, every non-terminal run's artifact and every resumable
 * (partial-upload) artifact. It returns a typed summary plus warnings and
 * never touches the filesystem itself.
 */
final class ArtifactRetentionServiceTest extends TestCase
{
    private const JAIL = '/jail/cast-exports';

    private const NOW = 2_000_000_000;

    private FakeArtifactStore $store;

    private InMemoryRunRepository $runs;

    private ArtifactRetentionService $service;

    protected function setUp(): void
    {
        $this->store = new FakeArtifactStore();
        $this->runs = new InMemoryRunRepository();
        $this->service = new ArtifactRetentionService($this->store, $this->runs);
    }

    public function testDisabledPolicyCollectsNothingAndReportsDisabled(): void
    {
        $this->store->add('run-old.zip', self::NOW - 9_000_000);

        $summary = $this->service->collect(self::NOW, RetentionPolicy::disabled());

        self::assertTrue($summary->disabled);
        self::assertSame(0, $summary->scanned);
        self::assertSame(0, $summary->deleted);
        self::assertSame(0, $this->store->deleteCalls);
        self::assertSame(0, $summary->retentionDays);
    }

    public function testUnavailableJailCollectsNothingWithAWarning(): void
    {
        $this->store->jail = null;
        $this->store->add('run-old.zip', self::NOW - 9_000_000);

        $summary = $this->service->collect(self::NOW, RetentionPolicy::fromDays(7));

        self::assertFalse($summary->jailAvailable);
        self::assertSame(0, $summary->scanned);
        self::assertSame(0, $summary->deleted);
        self::assertTrue($summary->hasWarnings());
        self::assertStringContainsString('jail', implode(' ', $summary->warnings));
    }

    public function testCollectsExpiredUnprotectedArtifacts(): void
    {
        $this->store->add('run-aaa.zip', self::NOW - 9_000_000);
        $this->store->add('run-bbb.zip', self::NOW - 8_000_000);

        $summary = $this->service->collect(self::NOW, RetentionPolicy::fromDays(7));

        self::assertSame(2, $summary->scanned);
        self::assertSame(2, $summary->deleted);
        self::assertSame(['run-aaa.zip', 'run-bbb.zip'], $summary->deletedNames);
        self::assertSame([], $this->store->names());
        self::assertSame([], $summary->warnings);
        self::assertFalse($summary->hasWarnings());
    }

    public function testYoungArtifactsAreSkippedAsNotDue(): void
    {
        // 2 days old: inside the 7-day window, must not be collected.
        $this->store->add('run-fresh.zip', self::NOW - 2 * 86400);

        $summary = $this->service->collect(self::NOW, RetentionPolicy::fromDays(7));

        self::assertSame(1, $summary->scanned);
        self::assertSame(0, $summary->deleted);
        self::assertSame(1, $summary->skippedNotDue);
        self::assertSame(['run-fresh.zip'], $this->store->names());
    }

    public function testLatestTerminalArtifactIsAlwaysProtectedEvenWhenExpired(): void
    {
        $latest = $this->terminalRun('run-latest', self::NOW - 9_000_000, 'run-latest.zip');
        $this->runs->save($latest);

        $this->store->add('run-latest.zip', self::NOW - 9_000_000);
        $this->store->add('run-old.zip', self::NOW - 9_000_000);

        $summary = $this->service->collect(self::NOW, RetentionPolicy::fromDays(7));

        self::assertContains('run-latest.zip', $summary->protected);
        self::assertSame(1, $summary->deleted);
        self::assertSame(['run-old.zip'], $summary->deletedNames);
        self::assertSame(['run-latest.zip'], $this->store->names());
    }

    public function testNonTerminalArtifactIsProtectedEvenWhenExpired(): void
    {
        $live = new ExportRun(
            runId: 'run-live',
            status: RunStatus::Running,
            updatedAt: 1_000,
            pack: $this->pack('run-live.zip'),
        );
        $this->runs->save($live);

        $this->store->add('run-live.zip', self::NOW - 9_000_000);

        $summary = $this->service->collect(self::NOW, RetentionPolicy::fromDays(7));

        self::assertContains('run-live.zip', $summary->protected);
        self::assertSame(0, $summary->deleted);
        self::assertSame(['run-live.zip'], $this->store->names());
    }

    public function testResumableArtifactWithPendingUploadIsProtectedEvenWhenTerminal(): void
    {
        // A Completed run that still owns a partial TUS upload identifier is a
        // resume source: its zip must survive GC.
        $resumable = new ExportRun(
            runId: 'run-resume',
            status: RunStatus::Completed,
            updatedAt: 1_000,
            lastUploadIdentifier: 'tus-session-1',
            pack: $this->pack('run-resume.zip'),
        );
        $this->runs->save($resumable);

        $this->store->add('run-resume.zip', self::NOW - 9_000_000);
        $this->store->add('run-old.zip', self::NOW - 9_000_000);

        $summary = $this->service->collect(self::NOW, RetentionPolicy::fromDays(7));

        self::assertContains('run-resume.zip', $summary->protected);
        self::assertSame(['run-old.zip'], $summary->deletedNames);
        self::assertSame(['run-resume.zip'], $this->store->names());
    }

    public function testOlderTerminalArtifactWithoutResumeStateIsCollected(): void
    {
        $old = $this->terminalRun('run-old', 1_000, 'run-old.zip');
        $this->runs->save($old);

        // A much newer run is the latest protected slot; the old terminal zip
        // has no pending upload and is past retention, so it is collectable.
        $latest = $this->terminalRun('run-latest', self::NOW, 'run-latest.zip');
        $this->runs->save($latest);

        $this->store->add('run-latest.zip', self::NOW - 100);
        $this->store->add('run-old.zip', self::NOW - 9_000_000);

        $summary = $this->service->collect(self::NOW, RetentionPolicy::fromDays(7));

        self::assertSame(['run-old.zip'], $summary->deletedNames);
        self::assertSame(['run-latest.zip'], $this->store->names());
    }

    public function testDeleteRefusalRecordsFailureAndWarnsWithoutAborting(): void
    {
        $this->store->add('run-aaa.zip', self::NOW - 9_000_000);
        $this->store->add('run-bbb.zip', self::NOW - 9_000_000);
        $this->store->refuseDelete('run-bbb.zip');

        $summary = $this->service->collect(self::NOW, RetentionPolicy::fromDays(7));

        self::assertSame(1, $summary->deleted);
        self::assertSame(1, $summary->deletionFailures);
        self::assertTrue($summary->hasWarnings());
        self::assertStringContainsString('run-bbb.zip', implode(' ', $summary->warnings));
        self::assertSame(['run-bbb.zip'], $this->store->names());
    }

    public function testSharedManifestIsNeverEnumeratedSoNeverDeleted(): void
    {
        // The store only surfaces *.zip artifacts; add a manifest name to any
        // list and prove the service reports scanned=0 for it (the service has
        // no manifest path of its own to delete).
        $this->store->add('run-a.zip', self::NOW - 9_000_000);

        $summary = $this->service->collect(self::NOW, RetentionPolicy::fromDays(7));

        self::assertSame(['run-a.zip'], $summary->deletedNames);
        self::assertNotContains('manifest.json', $summary->deletedNames);
        self::assertNotContains('manifest.json', $summary->protected);
    }

    public function testSummaryCarriesTypedRetentionFields(): void
    {
        $this->store->add('run-a.zip', self::NOW - 9_000_000);

        $summary = $this->service->collect(self::NOW, RetentionPolicy::fromDays(3));

        self::assertSame(self::NOW, $summary->now);
        self::assertSame(3, $summary->retentionDays);
        self::assertFalse($summary->disabled);
        self::assertTrue($summary->jailAvailable);
        self::assertSame(['run-a.zip'], $summary->deletedNames);
    }

    private function terminalRun(string $id, int $updatedAt, string $zipName): ExportRun
    {
        return new ExportRun(
            runId: $id,
            status: RunStatus::Completed,
            updatedAt: $updatedAt,
            pack: $this->pack($zipName),
        );
    }

    private function pack(string $zipName): PackResult
    {
        return new PackResult(
            PackStatus::Completed,
            10,
            0,
            100,
            self::JAIL . '/' . $zipName,
            self::JAIL . '/manifest.json',
        );
    }
}
