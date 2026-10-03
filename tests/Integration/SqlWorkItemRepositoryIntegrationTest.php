<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Integration;

use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Export\WorkItemStatus;
use LumeWeb\Cast\Persistence\CastExportItemsTable;
use LumeWeb\Cast\Persistence\SqlWorkItemRepository;
use LumeWeb\Cast\Persistence\WordPressWpDbGateway;
use PHPUnit\Framework\TestCase;

/**
 * Proves the {@see SqlWorkItemRepository} contract the capture workers run
 * against on the real database: the real {@see WordPressWpDbGateway} over the
 * live $wpdb and the `cast_export_items` table on the compose-provisioned
 * MariaDB.
 */
final class SqlWorkItemRepositoryIntegrationTest extends TestCase
{
    /** The run id the lease/claim tests operate on. */
    private const RUN_ID = 'run-integration';

    /** Rows enqueued by the claim-storm test. */
    private const STORM_ROWS = 8;

    /** Concurrent workers in the claim-storm test. */
    private const STORM_WORKERS = 4;

    /** Standalone worker script the claim storm spawns (one process each). */
    private const CLAIM_WORKER = __DIR__ . '/support/claim-worker.php';

    private string $table = '';

    protected function setUp(): void
    {
        global $wpdb;

        parent::setUp();

        $this->table = CastExportItemsTable::name($wpdb->prefix);
        $wpdb->query(CastExportItemsTable::dropSql($this->table));
        $wpdb->query(CastExportItemsTable::createSql($this->table, $wpdb->get_charset_collate()));
    }

    protected function tearDown(): void
    {
        global $wpdb;

        // Truncate, never DROP: other integration tests expect the shared
        // table to remain; setUp recreates it fresh per test.
        $wpdb->query("DELETE FROM {$this->table}");

        parent::tearDown();
    }

    /** A repository over the real $wpdb, exactly as the capture workers get one. */
    private function repository(): SqlWorkItemRepository
    {
        return new SqlWorkItemRepository(new WordPressWpDbGateway(), $this->table);
    }

    /**
     * Four separate processes, each with its own DB connection, claim from
     * the same table at once. The peek-then-conditional-UPDATE claim keeps
     * the union of their claims exactly the enqueued set only if the claim
     * is atomic on the shared table.
     */
    public function testConcurrentDistinctWorkerClaimsNeverReturnTheSameRow(): void
    {
        $factory = new WorkItemFactory();
        $repository = $this->repository();
        for ($i = 0; $i < self::STORM_ROWS; $i++) {
            $repository->insertCanonical(self::RUN_ID, $factory->fromString('https://example.com/storm/page-' . $i . '/'));
        }
        self::assertSame(self::STORM_ROWS, $repository->pendingCount(self::RUN_ID));

        $files = [];
        $processes = [];
        for ($worker = 0; $worker < self::STORM_WORKERS; $worker++) {
            $file = tempnam(sys_get_temp_dir(), 'cast-claim-');
            self::assertNotFalse($file);
            $files[] = $file;

            $command = implode(' ', array_map('escapeshellarg', [
                PHP_BINARY,
                self::CLAIM_WORKER,
                $this->wpCorePath(),
                $this->table,
                self::RUN_ID,
                'storm-worker-' . $worker,
                $file,
            ]));

            // All-pipe descriptors: this PHP build's proc_open rejects file
            // descriptors, and the shell redirection keeps the worker silent.
            $process = proc_open($command . ' >/dev/null 2>&1', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process, 'spawning the claim worker must succeed');
            fclose($pipes[0]);
            $processes[] = $process;
        }

        foreach ($processes as $process) {
            self::assertSame(0, proc_close($process), 'a claim worker exited non-zero');
        }

        $claimed = [];
        foreach ($files as $file) {
            foreach (array_filter(explode("\n", (string) file_get_contents($file))) as $hash) {
                $claimed[] = $hash;
            }
            unlink($file);
        }

        $this->assertCount(
            self::STORM_ROWS,
            $claimed,
            'every enqueued row is claimed exactly once across the concurrent workers',
        );
        $this->assertCount(
            count(array_unique($claimed)),
            $claimed,
            'two workers claimed the same row: ' . implode(', ', $claimed),
        );

        // The storm leaves every row in flight on a live lease.
        $this->assertSame(0, $this->repository()->countByStatus(self::RUN_ID, WorkItemStatus::Queued));
        $this->assertSame(self::STORM_ROWS, $this->repository()->countByStatus(self::RUN_ID, WorkItemStatus::Processing));
    }

    public function testLiveLeaseIsNeverStolenByAnotherWorker(): void
    {
        global $wpdb;

        $factory = new WorkItemFactory();
        $repository = $this->repository();
        $item = $factory->fromString('https://example.com/leased/');
        $repository->insertCanonical(self::RUN_ID, $item);

        $claimed = $repository->claimNext(self::RUN_ID, 'worker-a', 300);
        self::assertNotNull($claimed, 'the first worker must claim the sole queued row');
        $hash = $claimed->urlHash();

        // A fresh worker over the same table: a live lease leaves nothing for it to claim.
        $other = $this->repository();
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            self::assertNull(
                $other->claimNext(self::RUN_ID, 'worker-b', 300),
                "a live lease was stolen on attempt {$attempt}",
            );
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT status, worker_token, lease_expires_at FROM {$this->table} WHERE run_id = %s AND url_hash = %s",
            self::RUN_ID,
            $hash,
        ));
        self::assertNotNull($row);
        self::assertSame('processing', (string) $row->status);
        self::assertSame('worker-a', (string) $row->worker_token);
        self::assertGreaterThan(time(), (int) $row->lease_expires_at, 'the lease must still be live');
    }

    public function testExpiredLeaseIsReclaimedExactlyOnce(): void
    {
        $factory = new WorkItemFactory();
        $repository = $this->repository();
        $item = $factory->fromString('https://example.com/reclaimed/');
        $repository->insertCanonical(self::RUN_ID, $item);

        // Simulated crash: worker-a claims on a one-second lease and never finishes.
        $claimed = $repository->claimNext(self::RUN_ID, 'worker-a', 1);
        self::assertNotNull($claimed);
        $hash = $claimed->urlHash();
        self::assertSame(1, $repository->attemptCountOf(self::RUN_ID, $hash));

        sleep(2);

        $secondWorker = $this->repository();
        $reclaimed = $secondWorker->claimNext(self::RUN_ID, 'worker-b', 300);
        self::assertNotNull($reclaimed, 'an expired lease must be reclaimable');
        self::assertSame($hash, $reclaimed->urlHash(), 'the reclaimed row is the same one');
        self::assertSame(2, $secondWorker->attemptCountOf(self::RUN_ID, $hash), 'reclaim is the second attempt');

        // Every other worker, including the original claimant, now finds nothing.
        foreach (['worker-c', 'worker-d'] as $worker) {
            self::assertNull(
                $this->repository()->claimNext(self::RUN_ID, $worker, 300),
                "worker {$worker} claimed a row that worker-b holds on a live lease",
            );
        }
        self::assertNull(
            $repository->claimNext(self::RUN_ID, 'worker-a', 300),
            'the original (crashed) claimant cannot reclaim the row on top of worker-b',
        );
        self::assertSame(2, $secondWorker->attemptCountOf(self::RUN_ID, $hash), 'no further claim may increment attempts');
        self::assertSame(1, $secondWorker->countByStatus(self::RUN_ID, WorkItemStatus::Processing));
    }

    public function testClaimsAreScopedToTheRunThatOwnsTheRow(): void
    {
        $factory = new WorkItemFactory();
        $repository = $this->repository();
        $itemOne = $factory->fromString('https://example.com/run-one/');
        $itemTwo = $factory->fromString('https://example.com/run-two/');
        $repository->insertCanonical('run-one', $itemOne);
        $repository->insertCanonical('run-two', $itemTwo);

        $claimedOne = $repository->claimNext('run-one', 'worker-1', 300);
        self::assertNotNull($claimedOne);
        self::assertSame($itemOne->urlHash(), $claimedOne->urlHash());

        $claimedTwo = $this->repository()->claimNext('run-two', 'worker-2', 300);
        self::assertNotNull($claimedTwo);
        self::assertSame($itemTwo->urlHash(), $claimedTwo->urlHash());

        // Both rows are now leased, so neither run yields anything more.
        self::assertNull($repository->claimNext('run-one', 'worker-1', 300));
        self::assertNull($this->repository()->claimNext('run-two', 'worker-2', 300));

        self::assertTrue($repository->transition('run-one', $itemOne->urlHash(), WorkItemStatus::Done));
        self::assertSame(0, $repository->pendingCount('run-one'));
        self::assertSame(1, $repository->pendingCount('run-two'), 'run two must be untouched by run one\'s worker');

        self::assertTrue($repository->transition('run-two', $itemTwo->urlHash(), WorkItemStatus::Queued));
        $stillClaimable = $repository->claimNext('run-two', 'worker-2', 300);
        self::assertNotNull($stillClaimable);
        self::assertSame($itemTwo->urlHash(), $stillClaimable->urlHash());
        self::assertNull($repository->claimNext('run-two', 'worker-2', 300));
        self::assertSame(0, $repository->pendingCount('run-one'), 'run one stays drained');
    }

    /** Resolves the WP core directory: WP_CORE_DIR if valid, else the local checkout. */
    private function wpCorePath(): string
    {
        $core = getenv('WP_CORE_DIR');
        if ($core !== false && is_file($core . '/wp-settings.php')) {
            return $core;
        }

        $root = dirname(__DIR__, 2);
        foreach ([$root . '/var/wordpress', $root . '/vendor/roots/wordpress-no-content'] as $candidate) {
            if (is_file($candidate . '/wp-settings.php')) {
                return $candidate;
            }
        }

        self::fail('No WordPress core checkout found for the claim workers.');
    }
}
