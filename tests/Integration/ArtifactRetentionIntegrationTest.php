<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Integration;

use LumeWeb\Cast\Export\ArtifactRetentionService;
use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\PackResult;
use LumeWeb\Cast\Export\PackStatus;
use LumeWeb\Cast\Export\RetentionPolicy;
use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Export\WordPressArtifactStore;
use LumeWeb\Cast\Jobs\RetentionScheduler;
use LumeWeb\Cast\Jobs\WordPressActionScheduler;
use LumeWeb\Cast\Persistence\WordPressOptionGateway;
use LumeWeb\Cast\Persistence\WordPressRunRepository;
use PHPUnit\Framework\TestCase;

/**
 * Real WordPress + real filesystem artifact retention GC.
 *
 * The plugin is already booted by the harness, so the retention hook is
 * registered under a live WordPress install. These tests drive the REAL
 * WordPressArtifactStore against the REAL uploads directory (the same
 * `cast-exports` jail Pack writes into), the REAL option-backed run
 * repository and the REAL pure service, then assert the jail safety and
 * protection rules on disk: expired, unprotected ZIPs are collected; the
 * shared manifest.json and the latest run's artifact survive; a path outside
 * the jail is never removed; and retention can be disabled through the
 * `cast_publish_retention_days` option.
 */
final class ArtifactRetentionIntegrationTest extends TestCase
{
    private string $jail;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/cast.php';

        $uploads = wp_upload_dir();
        $this->assertIsArray($uploads);
        $this->assertArrayHasKey('basedir', $uploads);
        $this->jail = rtrim((string) $uploads['basedir'], '/\\') . '/cast-exports';
        if (!is_dir($this->jail)) {
            mkdir($this->jail, 0777, true);
        }

        $this->clearJail();
        delete_option(WordPressRunRepository::OPTION_KEY);
        delete_option(RetentionPolicy::OPTION);
    }

    protected function tearDown(): void
    {
        $this->clearJail();
        delete_option(WordPressRunRepository::OPTION_KEY);
        delete_option(RetentionPolicy::OPTION);
        as_unschedule_all_actions(RetentionScheduler::RETENTION_HOOK, [], WordPressActionScheduler::DEFAULT_GROUP);
    }

    public function testBootedCompositionRegistersTheRetentionHook(): void
    {
        // Lifecycle coverage: the real boot wires the retention sweep action.
        self::assertNotFalse(has_action(RetentionScheduler::RETENTION_HOOK));
    }

    public function testSweepCollectsExpiredArtifactsAndProtectsManifestAndLatestRunUnderRealWordPress(): void
    {
        $runZip = $this->writeZip('run-latest.zip', 1_000_000_000);
        $oldZip = $this->writeZip('run-old.zip', 1_000_000_000);
        file_put_contents($this->jail . '/manifest.json', '{}');
        touch($this->jail . '/manifest.json', 1_000_000_000);

        // The single run slot holds a terminal run whose artifact is the latest
        // zip: the sweep must keep it (and the shared manifest) while it
        // collects only the unprotected old zip.
        $repository = new WordPressRunRepository(new WordPressOptionGateway());
        $repository->save(new ExportRun(
            runId: 'run-latest',
            status: RunStatus::Completed,
            updatedAt: 2_000_000_000,
            pack: new PackResult(
                PackStatus::Completed,
                10,
                0,
                100,
                $runZip,
                $this->jail . '/manifest.json',
            ),
        ));

        $store = new WordPressArtifactStore();
        $summary = (new ArtifactRetentionService($store, $repository))
            ->collect(2_000_000_000, RetentionPolicy::fromDays(7));

        self::assertSame(2, $summary->scanned);
        self::assertSame(1, $summary->deleted);
        self::assertSame(['run-old.zip'], $summary->deletedNames);
        self::assertContains('run-latest.zip', $summary->protected);
        self::assertFileExists($runZip, 'The latest run artifact must survive the sweep.');
        self::assertFileExists($this->jail . '/manifest.json', 'The shared manifest must never be deleted.');
        self::assertFileDoesNotExist($oldZip, 'The expired, unprotected artifact must be collected.');
    }

    public function testDisabledRetentionOptionCollectsNothingUnderRealWordPress(): void
    {
        update_option(RetentionPolicy::OPTION, '0', false);
        $oldZip = $this->writeZip('run-old.zip', 1_000_000_000);

        $store = new WordPressArtifactStore();
        $policy = RetentionPolicy::fromOption(get_option(RetentionPolicy::OPTION, null));
        $summary = (new ArtifactRetentionService($store, new WordPressRunRepository(new WordPressOptionGateway())))
            ->collect(2_000_000_000, $policy);

        self::assertTrue($summary->disabled);
        self::assertSame(0, $summary->deleted);
        self::assertFileExists($oldZip);
        delete_option(RetentionPolicy::OPTION);
    }

    public function testStoreRefusesAZipOutsideTheJailUnderRealWordPress(): void
    {
        $uploads = wp_upload_dir();
        $outside = rtrim((string) $uploads['basedir'], '/\\') . '/outside-retention.zip';
        @unlink($outside);
        file_put_contents($outside, 'OUT');

        try {
            $store = new WordPressArtifactStore();

            self::assertFalse($store->delete($outside), 'A realpath outside the jail must never be deleted.');
            self::assertFileExists($outside);
        } finally {
            @unlink($outside);
        }
    }

    public function testSweepHeedsRealMtimesWithAnOptionDrivenPolicy(): void
    {
        // A fresh artifact (recent mtime) stays even though retention is on.
        $fresh = $this->writeZip('run-fresh.zip', time());

        $summary = (new ArtifactRetentionService(
            new WordPressArtifactStore(),
            new WordPressRunRepository(new WordPressOptionGateway()),
        ))->collect(time(), RetentionPolicy::fromDays(7));

        self::assertSame(1, $summary->scanned);
        self::assertSame(0, $summary->deleted);
        self::assertFileExists($fresh);
    }

    private function writeZip(string $name, int $mtime): string
    {
        $path = $this->jail . '/' . $name;
        file_put_contents($path, 'ZIP');
        touch($path, $mtime);

        return $path;
    }

    /**
     * Remove every test artifact from the jar so each test starts clean and no
     * real file survives the run.
     */
    private function clearJail(): void
    {
        if (!is_dir($this->jail)) {
            return;
        }

        foreach (scandir($this->jail) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->jail . '/' . $entry;
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                (new WordPressArtifactStore())->delete($path);
            }
        }
    }
}
