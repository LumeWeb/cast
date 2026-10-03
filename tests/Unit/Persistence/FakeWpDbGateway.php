<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Persistence;

use LumeWeb\Cast\Persistence\WpDbGateway;

/**
 * In-memory WpDbGateway faithful to the MariaDB storage semantics the durable
 * queue depends on: a unique (run_id, url_hash) index, integer retry_at
 * gating, lease-based atomic claims (worker token + lease expiry), and the
 * exact statement vocabulary {@see \LumeWeb\Cast\Persistence\SqlWorkItemRepository}
 * emits. The repository's decision logic (first-successful-doesn't-win,
 * lower-priority-replaces, exclusive claims, lease expiry) lives in the
 * repository, not here: this fake only stores rows and interprets the bounded
 * SQL surface.
 *
 * Rows are scoped by run id (nothing ever crosses run boundaries) and ordered
 * by insertion order (which stands in for AUTO_INCREMENT id), so ORDER BY
 * priority ASC, id ASC resolves deterministically like MariaDB.
 */
final class FakeWpDbGateway implements WpDbGateway
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $rows = [];

    /**
     * Every statement dispatched, before wpdb-style substitution: [sql, params].
     *
     * @var list<array{0: string, 1: list<mixed>}> $prepared
     */
    public array $prepared = [];

    public function query(string $sql, array $params = []): int
    {
        $this->prepared[] = [$sql, array_values($params)];

        if (str_contains($sql, 'DELETE FROM')) {
            // The repository's clear(runId) empties one run's slice of the
            // shared queue; wpdb returns the number of deleted rows.
            $deleted = 0;
            foreach ($this->rows as $index => $row) {
                if ($row['run_id'] === $params[0]) {
                    unset($this->rows[$index]);
                    ++$deleted;
                }
            }
            $this->rows = array_values($this->rows);

            return $deleted;
        }

        if (str_contains($sql, 'INSERT INTO')) {
            return $this->insert($params);
        }

        if (str_contains($sql, 'fetch_attempts = fetch_attempts + 1')) {
            return $this->claim($params);
        }

        if (str_contains($sql, 'retry_at = ')) {
            return $this->retry($params);
        }

        if (str_contains($sql, 'SET priority = ')) {
            return $this->setPriority($params);
        }

        // Plain status transition: UPDATE ... SET status = %s, worker_token = '',
        // lease_expires_at = 0 WHERE run_id = %s AND url_hash = %s
        return $this->transition($params);
    }

    public function getVar(string $sql, array $params = []): mixed
    {
        $this->prepared[] = [$sql, array_values($params)];

        if (str_contains($sql, 'ORDER BY priority') && str_contains($sql, 'SELECT url_hash FROM')) {
            // Claim peek: [runId, 'queued', now, 'processing', now].
            return $this->nextClaimableHash((string) $params[0], (int) $params[2]);
        }

        if (str_contains($sql, 'SELECT url_hash FROM')) {
            // Existence check: [runId, urlHash].
            $row = $this->find((string) $params[0], (string) $params[1]);

            return $row === null ? null : $row['url_hash'];
        }

        if (str_contains($sql, 'SELECT priority FROM')) {
            $row = $this->find((string) $params[0], (string) $params[1]);

            return $row === null ? null : $row['priority'];
        }

        if (str_contains($sql, 'SELECT fetch_attempts FROM')) {
            $row = $this->find((string) $params[0], (string) $params[1]);

            return $row === null ? 0 : $row['fetch_attempts'];
        }

        if (str_contains($sql, 'SELECT status FROM')) {
            $row = $this->find((string) $params[0], (string) $params[1]);

            return $row === null ? null : $row['status'];
        }

        if (str_contains($sql, 'status IN')) {
            // [runId, 'queued', 'processing'].
            $pending = 0;
            foreach ($this->rowsInRun((string) $params[0]) as $row) {
                if (in_array($row['status'], ['queued', 'processing'], true)) {
                    ++$pending;
                }
            }

            return $pending;
        }

        // SELECT COUNT(*) FROM ... WHERE run_id = %s AND status = %s
        $count = 0;
        foreach ($this->rowsInRun((string) $params[0]) as $row) {
            if ($row['status'] === $params[1]) {
                ++$count;
            }
        }

        return $count;
    }

    public function getRow(string $sql, array $params = []): ?array
    {
        $this->prepared[] = [$sql, array_values($params)];

        if (str_contains($sql, 'kind IN')) {
            // First rewritable (done) row of the run, lowest priority first.
            $candidates = [];
            foreach ($this->rowsInRun((string) $params[0]) as $row) {
                if ($row['status'] !== 'done') {
                    continue;
                }
                if (!$this->isRewritable($row)) {
                    continue;
                }
                $candidates[] = $row;
            }
            usort($candidates, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

            return $candidates === [] ? null : $candidates[0];
        }

        // Full row by (run_id, url_hash): SELECT ... WHERE run_id = %s AND url_hash = %s
        return $this->find((string) $params[0], (string) $params[1]);
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /**
     * The stored row for a (run, hash) pair, for lease-ownership assertions.
     *
     * @return array<string, mixed>|null
     */
    public function rowFor(string $runId, string $hash): ?array
    {
        return $this->find($runId, $hash);
    }

    /**
     * @param list<string|int> $params
     */
    private function insert(array $params): int
    {
        [$runId, $hash, $url, $identity, $path, $kind, $priority, $status, $fetchAttempts, $retryAt, $workerToken, $leaseExpiresAt] = $params;

        if ($this->find((string) $runId, (string) $hash) !== null) {
            // Unique (run_id, url_hash) index rejects a duplicate row in the
            // same run; the same hash in ANOTHER run is a distinct row.
            return 0;
        }

        $this->rows[] = [
            'run_id' => $runId,
            'url_hash' => $hash,
            'url' => $url,
            'identity' => $identity,
            'path' => $path,
            'kind' => $kind,
            'priority' => $priority,
            'status' => $status,
            'fetch_attempts' => $fetchAttempts,
            'retry_at' => $retryAt,
            'worker_token' => $workerToken,
            'lease_expires_at' => $leaseExpiresAt,
        ];

        return 1;
    }

    /**
     * Atomic conditional lease claim: only a queued row whose retry is due,
     * or an in-flight row whose lease has expired, becomes (re-)claimed; the
     * claiming worker token and the new lease expiry are recorded and the
     * attempt count advances exactly once.
     *
     * @param list<string|int> $params
     */
    private function claim(array $params): int
    {
        // [processing, worker, leaseExpiresAt, runId, hash, queued, now, processing, now]
        [$processing, $worker, $leaseExpiresAt, $runId, $hash, $queued, $now] = $params;
        $row = $this->find((string) $runId, (string) $hash);

        if ($row === null || !$this->isClaimable($row, (string) $queued, (string) $processing, (int) $now)) {
            return 0;
        }

        $row['status'] = $processing;
        $row['worker_token'] = $worker;
        $row['lease_expires_at'] = $leaseExpiresAt;
        $row['fetch_attempts'] = (int) $row['fetch_attempts'] + 1;
        $this->replace($row);

        return 1;
    }

    /**
     * @param list<string|int> $params
     */
    private function retry(array $params): int
    {
        // [queued, retryAt, runId, hash]
        [$queued, $retryAt, $runId, $hash] = $params;
        $row = $this->find((string) $runId, (string) $hash);

        if ($row === null) {
            return 0;
        }

        // Same changed-row semantics as transition(): re-queuing a row whose
        // status, retry time and lease state already match affects 0 rows in
        // MariaDB, so the repository's existence re-check, not the affected
        // count, decides.
        if (
            $row['status'] === $queued && (int) $row['retry_at'] === $retryAt
            && $row['worker_token'] === '' && (int) $row['lease_expires_at'] === 0
        ) {
            return 0;
        }

        $row['status'] = $queued;
        $row['retry_at'] = $retryAt;
        $row['worker_token'] = '';
        $row['lease_expires_at'] = 0;
        $this->replace($row);

        return 1;
    }

    /**
     * @param list<string|int> $params
     */
    private function setPriority(array $params): int
    {
        // [priority, runId, hash]
        [$priority, $runId, $hash] = $params;
        $row = $this->find((string) $runId, (string) $hash);

        if ($row === null) {
            return 0;
        }

        $row['priority'] = $priority;
        $this->replace($row);

        return 1;
    }

    /**
     * @param list<string|int> $params
     */
    private function transition(array $params): int
    {
        // [status, runId, hash]
        [$status, $runId, $hash] = $params;
        $row = $this->find((string) $runId, (string) $hash);

        if ($row === null) {
            return 0;
        }

        // MariaDB reports changed rows, not matched rows: a transition that
        // sets the status, worker token and lease to their stored values
        // affects 0 rows even though the row exists. The repository
        // re-checks existence after the write to disambigate
        // unchanged-but-present from an unknown hash.
        if ($row['status'] === $status && $row['worker_token'] === '' && (int) $row['lease_expires_at'] === 0) {
            return 0;
        }

        $row['status'] = $status;
        $row['worker_token'] = '';
        $row['lease_expires_at'] = 0;
        $this->replace($row);

        return 1;
    }

    /**
     * Claim peek: the lowest-priority row of the run that is queued with a
     * due retry, or in flight on an expired lease.
     */
    private function nextClaimableHash(string $runId, int $now): ?string
    {
        $candidates = [];
        foreach ($this->rowsInRun($runId) as $row) {
            if ($this->isClaimable($row, 'queued', 'processing', $now)) {
                $candidates[] = $row;
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return (string) $candidates[0]['url_hash'];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isClaimable(array $row, string $queued, string $processing, int $now): bool
    {
        if ($row['status'] === $queued) {
            return (int) $row['retry_at'] <= $now;
        }

        // An in-flight row is reclaimable only when it carries a lease and
        // that lease has lapsed; a live lease is never stolen.
        return $row['status'] === $processing
            && (int) $row['lease_expires_at'] > 0
            && (int) $row['lease_expires_at'] <= $now;
    }

    /**
     * Matches the SQL rewritability predicate the repository pushes down: done
     * rows whose kind is page/redirect/text, or an asset with a text-capable
     * extension.
     *
     * @param array<string, mixed> $row
     */
    private function isRewritable(array $row): bool
    {
        $kind = (string) $row['kind'];
        if (in_array($kind, ['page', 'redirect', 'text'], true)) {
            return true;
        }

        if ($kind !== 'asset') {
            return false;
        }

        $extension = strtolower((string) strrchr((string) $row['path'], '.'));
        if ($extension !== '' && $extension[0] === '.') {
            $extension = substr($extension, 1);
        }

        return in_array($extension, ['css', 'js', 'mjs', 'json', 'xml', 'rss', 'atom'], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsInRun(string $runId): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => $row['run_id'] === $runId,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $runId, string $hash): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['run_id'] === $runId && $row['url_hash'] === $hash) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function replace(array $row): void
    {
        foreach ($this->rows as $index => $existing) {
            if ($existing['run_id'] === $row['run_id'] && $existing['url_hash'] === $row['url_hash']) {
                $this->rows[$index] = $row;

                return;
            }
        }
    }
}
