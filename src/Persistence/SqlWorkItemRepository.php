<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Persistence;

use LumeWeb\Cast\Export\InsertOutcome;
use LumeWeb\Cast\Export\Url;
use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemKind;
use LumeWeb\Cast\Export\WorkItemRepository;
use LumeWeb\Cast\Export\WorkItemStatus;

/**
 * Durable, MariaDB-backed WorkItemRepository. Every operation is scoped to
 * the run id that owns the work: rows of one run are invisible to every other
 * run, and the table's unique key is (run_id, url_hash), so the same URL is a
 * distinct row in each run.
 *
 * Claims are lease-based: the two-step "peek then conditional UPDATE"
 * pattern makes a claim atomic on a single shared table. The peek (SELECT
 * url_hash ... ORDER BY priority ASC, id ASC LIMIT 1) picks the lowest
 * numeric priority row that is queued with a due retry, or in flight on an
 * expired lease; the conditional UPDATE matches (run_id, url_hash, expected
 * status, retry gate, lease gate) so a row whose state changed between the
 * two statements affects zero rows and the claim is simply empty; two
 * workers can never claim the same row. The UPDATE records the claiming worker token
 * and the lease expiry, and advances the attempt counter in the same atomic
 * statement (fetch_attempts = fetch_attempts + 1), so a crashed worker's
 * in-flight row is reclaimable once its lease lapses; a live lease is
 * never stolen.
 *
 * wpdb notes that shape this SQL:
 *  - The items table is BIGINT UNSIGNED and uses the site collation, so all
 *    timestamps are Unix seconds (BIGINT), never DATETIME: portable across
 *    collations and trivially comparable in WHERE clauses.
 *  - %d placeholders for ints, %s for strings; prepare() returns the
 *    substituted SQL string, and query()/getVar()/getRow() take that string.
 *  - MariaDB reports CHANGED rows, not matched rows: an UPDATE whose WHERE
 *    matched a row but whose values were all identical returns 0. The
 *    repository disambiguates "unchanged-but-present" from "unknown hash"
 *    with a follow-up existence check after a 0-affected transition.
 *
 * The repository is constructed with the concrete (already-prefixed) table
 * name and the shared WpDbGateway, the connection-scoped access point to
 * $wpdb the whole plugin uses.
 */
final class SqlWorkItemRepository implements WorkItemRepository
{
    /**
     * @param \Closure(): int $clock Returns the current Unix time (seconds).
     */
    public function __construct(
        private readonly WpDbGateway $db,
        private readonly string $table,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * @var \Closure(): int
     */
    private \Closure $clock;

    public function insertCanonical(string $runId, WorkItem $workItem, int $priority = 10): InsertOutcome
    {
        $hash = $workItem->urlHash();

        $existing = $this->db->getVar(
            $this->sql("SELECT status FROM {$this->table} WHERE run_id = %s AND url_hash = %s"),
            [$runId, $hash],
        );

        if ($existing === null) {
            $inserted = $this->db->query(
                $this->sql("INSERT INTO {$this->table}
                    (run_id, url_hash, url, identity, path, kind, priority, status, fetch_attempts, retry_at, worker_token, lease_expires_at)
                 VALUES (%s, %s, %s, %s, %s, %s, %d, %s, %d, %d, %s, %d)"),
                [
                    $runId,
                    $hash,
                    (string) $workItem->url(),
                    $workItem->identity(),
                    $workItem->outputPath(),
                    $workItem->kind()->value,
                    $priority,
                    WorkItemStatus::Queued->value,
                    0,
                    0,
                    '',
                    0,
                ],
            );

            return $inserted === 1 ? InsertOutcome::created() : InsertOutcome::duplicate();
        }

        // Row present in this run: first-seen-wins semantics.
        if ($existing === WorkItemStatus::Done->value) {
            return InsertOutcome::duplicate();
        }

        $priorityRow = $this->db->getRow(
            $this->sql("SELECT priority FROM {$this->table} WHERE run_id = %s AND url_hash = %s"),
            [$runId, $hash],
        );
        $storedPriority = $priorityRow === null ? null : (int) $priorityRow['priority'];
        if ($storedPriority !== null && $priority < $storedPriority) {
            $this->db->query(
                $this->sql("UPDATE {$this->table} SET priority = %d WHERE run_id = %s AND url_hash = %s"),
                [$priority, $runId, $hash],
            );

            return InsertOutcome::priorityReplaced();
        }

        return InsertOutcome::duplicate();
    }

    public function priorityOf(string $runId, string $urlHash): ?int
    {
        $value = $this->db->getVar(
            $this->sql("SELECT priority FROM {$this->table} WHERE run_id = %s AND url_hash = %s"),
            [$runId, $urlHash],
        );

        return $value === null ? null : (int) $value;
    }

    public function countByStatus(string $runId, WorkItemStatus $status): int
    {
        $count = $this->db->getVar(
            $this->sql("SELECT COUNT(*) FROM {$this->table} WHERE run_id = %s AND status = %s"),
            [$runId, $status->value],
        );

        return (int) $count;
    }

    public function pendingCount(string $runId): int
    {
        $count = $this->db->getVar(
            $this->sql("SELECT COUNT(*) FROM {$this->table} WHERE run_id = %s AND status IN ('queued', 'processing')"),
            [$runId],
        );

        return (int) $count;
    }

    public function hasPending(string $runId): bool
    {
        return $this->pendingCount($runId) > 0;
    }

    public function transition(string $runId, string $urlHash, WorkItemStatus $status): bool
    {
        $updated = $this->db->query(
            $this->sql("UPDATE {$this->table}
                SET status = %s, worker_token = '', lease_expires_at = 0
                WHERE run_id = %s AND url_hash = %s"),
            [$status->value, $runId, $urlHash],
        );

        if ($updated > 0) {
            return true;
        }

        // MariaDB reports changed rows, not matched rows: a transition that
        // sets the status, worker token and lease to their stored values
        // affects 0 rows even though the row exists. Re-check existence to
        // disambigate unchanged-but-present (true) from an unknown hash
        // (false).
        return $this->rowExists($runId, $urlHash);
    }

    public function claimNext(string $runId, string $worker = WorkItemRepository::DEFAULT_WORKER, int $leaseSeconds = WorkItemRepository::DEFAULT_LEASE_SECONDS): ?WorkItem
    {
        $now = ($this->clock)();
        $leaseExpiresAt = $now + max(0, $leaseSeconds);
        $queued = WorkItemStatus::Queued->value;
        $processing = WorkItemStatus::Processing->value;

        // Peek: the lowest-priority claimable row of this run; id is the
        // tiebreaker, so ordering is deterministic.
        $hash = $this->db->getVar(
            $this->sql("SELECT url_hash FROM {$this->table}
                WHERE run_id = %s
                  AND (
                        (status = %s AND retry_at <= %d)
                        OR (status = %s AND lease_expires_at > 0 AND lease_expires_at <= %d)
                      )
                ORDER BY priority ASC, id ASC
                LIMIT 1"),
            [$runId, $queued, $now, $processing, $now],
        );

        if ($hash === null) {
            return null;
        }

        // Claim: the conditional UPDATE matches the exact state the peek saw,
        // so a row whose state changed in between affects zero rows and the
        // claim is empty. It records the worker token, the lease expiry and
        // the attempt count in one atomic statement.
        $claimed = $this->db->query(
            $this->sql("UPDATE {$this->table}
                SET status = %s,
                    worker_token = %s,
                    lease_expires_at = %d,
                    fetch_attempts = fetch_attempts + 1
                WHERE run_id = %s AND url_hash = %s
                  AND (
                        (status = %s AND retry_at <= %d)
                        OR (status = %s AND lease_expires_at > 0 AND lease_expires_at <= %d)
                      )"),
            [
                $processing,
                $worker,
                $leaseExpiresAt,
                $runId,
                $hash,
                $queued,
                $now,
                $processing,
                $now,
            ],
        );

        if ($claimed !== 1) {
            return null;
        }

        $row = $this->db->getRow(
            $this->sql("SELECT url_hash, url, identity, path, kind, priority FROM {$this->table} WHERE run_id = %s AND url_hash = %s"),
            [$runId, $hash],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function attemptCountOf(string $runId, string $urlHash): int
    {
        $count = $this->db->getVar(
            $this->sql("SELECT fetch_attempts FROM {$this->table} WHERE run_id = %s AND url_hash = %s"),
            [$runId, $urlHash],
        );

        return $count === null ? 0 : (int) $count;
    }

    public function scheduleRetry(string $runId, string $urlHash, int $delaySeconds): bool
    {
        $retryAt = ($this->clock)() + max(0, $delaySeconds);

        $this->db->query(
            $this->sql("UPDATE {$this->table}
                SET status = %s, retry_at = %d, worker_token = '', lease_expires_at = 0
                WHERE run_id = %s AND url_hash = %s"),
            [WorkItemStatus::Queued->value, $retryAt, $runId, $urlHash],
        );

        // Changed-row semantics: 0 affected also means the row already had
        // this exact state and is still queued for retry. Existence is the
        // contract.
        return $this->rowExists($runId, $urlHash);
    }

    public function claimNextRewritable(string $runId): ?WorkItem
    {
        $row = $this->db->getRow(
            $this->sql("SELECT url_hash, url, identity, path, kind, priority FROM {$this->table}
                WHERE run_id = %s
                  AND status = %s
                  AND (kind IN ('page', 'redirect', 'text')
                       OR (kind = 'asset' AND path LIKE %s ESCAPE '\\\\')
                       OR (kind = 'asset' AND path LIKE %s ESCAPE '\\\\')
                       OR (kind = 'asset' AND path LIKE %s ESCAPE '\\\\')
                       OR (kind = 'asset' AND path LIKE %s ESCAPE '\\\\')
                       OR (kind = 'asset' AND path LIKE %s ESCAPE '\\\\')
                       OR (kind = 'asset' AND path LIKE %s ESCAPE '\\\\')
                       OR (kind = 'asset' AND path LIKE %s ESCAPE '\\\\'))
                ORDER BY priority ASC, id ASC
                LIMIT 1"),
            [
                $runId,
                WorkItemStatus::Done->value,
                '%.css',
                '%.js',
                '%.mjs',
                '%.json',
                '%.xml',
                '%.rss',
                '%.atom',
            ],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function clear(string $runId): void
    {
        // The cleared run's terminal rows must not survive: first-seen-wins
        // would starve the fresh per-run work directory with stale rows.
        $this->db->query(
            $this->sql("DELETE FROM {$this->table} WHERE run_id = %s"),
            [$runId],
        );
    }

    public function purgeTerminalRuns(array $runIds): void
    {
        $runIds = array_values(array_unique(array_filter(
            $runIds,
            static fn (string $id): bool => $id !== '',
        )));

        if ($runIds === []) {
            // Nothing to prune: no statement is dispatched at all.
            return;
        }

        // One scoped DELETE over exactly the listed run ids; rows of any run
        // not in the list (the fresh run, live nonterminal runs) are left
        // untouched. Run ids are trusted internal identifiers, bound with %s.
        $placeholders = implode(', ', array_fill(0, count($runIds), '%s'));
        $this->db->query(
            $this->sql("DELETE FROM {$this->table} WHERE run_id IN ({$placeholders})"),
            $runIds,
        );
    }

    private function rowExists(string $runId, string $urlHash): bool
    {
        $exists = $this->db->getVar(
            $this->sql("SELECT url_hash FROM {$this->table} WHERE run_id = %s AND url_hash = %s"),
            [$runId, $urlHash],
        );

        return $exists !== null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): WorkItem
    {
        return new WorkItem(
            $this->parseUrl((string) $row['url']),
            WorkItemKind::from((string) $row['kind']),
            (string) $row['identity'],
            (string) $row['url_hash'],
            (string) $row['path'],
        );
    }

    private function parseUrl(string $url): Url
    {
        $parts = parse_url($url);

        if ($parts === false) {
            // The canonicalizer never writes a malformed URL, but a corrupted
            // row must not fatal the worker; fall back to an empty URL.
            return new Url('', '', null, '', '');
        }

        return new Url(
            (string) ($parts['scheme'] ?? ''),
            (string) ($parts['host'] ?? ''),
            isset($parts['port']) ? (int) $parts['port'] : null,
            (string) ($parts['path'] ?? ''),
            (string) ($parts['query'] ?? ''),
        );
    }

    private function sql(string $sql): string
    {
        return $sql;
    }
}
