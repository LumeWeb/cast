<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Persistence;

use LumeWeb\Cast\Persistence\WpDbGateway;

/**
 * In-memory WpDbGateway faithful to the MariaDB storage semantics the durable
 * queue depends on: a unique url_hash index, integer retry_at, and the exact
 * statement vocabulary {@see \LumeWeb\Cast\Persistence\SqlWorkItemRepository}
 * emits. The repository's decision logic (first-successful-doesn't-win,
 * lower-priority-replaces, atomic conditional claims) lives in the repository,
 * not here — this fake only stores rows and interprets the bounded SQL surface.
 *
 * Rows are ordered by insertion order (which stands in for AUTO_INCREMENT id),
 * so ORDER BY priority ASC, id ASC resolves deterministically like MariaDB.
 */
final class FakeWpDbGateway implements WpDbGateway
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $rows = [];

    public function query(string $sql, array $params = []): int
    {
        if (str_contains($sql, 'DELETE FROM')) {
            // The repository's clear() empties the shared queue per new run;
            // wpdb returns the number of deleted rows.
            $deleted = count($this->rows);
            $this->rows = [];

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

        // Plain status transition: UPDATE ... SET status = %s WHERE url_hash = %s
        return $this->transition($params);
    }

    public function getVar(string $sql, array $params = []): mixed
    {
        if (str_contains($sql, 'SELECT url_hash FROM')) {
            return $this->nextQueuedHash((int) $params[1]);
        }

        if (str_contains($sql, 'SELECT priority FROM')) {
            $row = $this->find((string) $params[0]);

            return $row === null ? null : $row['priority'];
        }

        if (str_contains($sql, 'SELECT fetch_attempts FROM')) {
            $row = $this->find((string) $params[0]);

            return $row === null ? 0 : $row['fetch_attempts'];
        }

        if (str_contains($sql, 'status IN')) {
            $pending = 0;
            foreach ($this->rows as $row) {
                if (in_array($row['status'], ['queued', 'processing'], true)) {
                    ++$pending;
                }
            }

            return $pending;
        }

        // SELECT COUNT(*) FROM ... WHERE status = %s
        $status = (string) $params[0];
        $count = 0;
        foreach ($this->rows as $row) {
            if ($row['status'] === $status) {
                ++$count;
            }
        }

        return $count;
    }

    public function getRow(string $sql, array $params = []): ?array
    {
        if (str_contains($sql, 'SELECT status, priority FROM')) {
            $row = $this->find((string) $params[0]);
            if ($row === null) {
                return null;
            }

            return ['status' => $row['status'], 'priority' => $row['priority']];
        }

        if (str_contains($sql, 'WHERE status = ')) {
            // First rewritable (done) row, lowest priority first.
            $candidates = [];
            foreach ($this->rows as $row) {
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

        // Full row by url_hash: SELECT ... FROM ... WHERE url_hash = %s
        return $this->find((string) $params[0]);
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /**
     * @param list<string|int> $params
     */
    private function insert(array $params): int
    {
        [$hash, $url, $identity, $path, $kind, $priority, $status, $fetchAttempts, $retryAt] = $params;

        if ($this->find((string) $hash) !== null) {
            // Unique url_hash index rejects a duplicate row.
            return 0;
        }

        $this->rows[] = [
            'url_hash' => $hash,
            'url' => $url,
            'identity' => $identity,
            'path' => $path,
            'kind' => $kind,
            'priority' => $priority,
            'status' => $status,
            'fetch_attempts' => $fetchAttempts,
            'retry_at' => $retryAt,
        ];

        return 1;
    }

    /**
     * Atomic conditional claim: only a queued row may become processing and its
     * attempt count advances exactly once.
     *
     * @param list<string|int> $params
     */
    private function claim(array $params): int
    {
        [$processing, $hash, $queued] = $params;
        $row = $this->find((string) $hash);

        if ($row === null || $row['status'] !== $queued) {
            return 0;
        }

        $row['status'] = $processing;
        $row['fetch_attempts'] = (int) $row['fetch_attempts'] + 1;
        $this->replace($row);

        return 1;
    }

    /**
     * @param list<string|int> $params
     */
    private function retry(array $params): int
    {
        [$queued, $retryAt, $hash] = $params;
        $row = $this->find((string) $hash);

        if ($row === null) {
            return 0;
        }

        // Same changed-row semantics as transition(): re-queuing a row whose
        // status and retry time already match affects 0 rows in MariaDB, so the
        // repository's existence re-check, not the affected count, decides.
        if ($row['status'] === $queued && (int) $row['retry_at'] === $retryAt) {
            return 0;
        }

        $row['status'] = $queued;
        $row['retry_at'] = $retryAt;
        $this->replace($row);

        return 1;
    }

    /**
     * @param list<string|int> $params
     */
    private function setPriority(array $params): int
    {
        [$priority, $hash] = $params;
        $row = $this->find((string) $hash);

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
        [$status, $hash] = $params;
        $row = $this->find((string) $hash);

        if ($row === null) {
            return 0;
        }

        // MariaDB reports changed rows, not matched rows: an UPDATE that sets
        // the status to its stored value affects 0 rows even though the row
        // exists. The repository re-checks existence after the write to
        // disambiguate unchanged-but-present from an unknown hash.
        if ($row['status'] === $status) {
            return 0;
        }

        $row['status'] = $status;
        $this->replace($row);

        return 1;
    }

    private function nextQueuedHash(int $now): ?string
    {
        $candidates = [];
        foreach ($this->rows as $row) {
            if ($row['status'] === 'queued' && (int) $row['retry_at'] <= $now) {
                $candidates[] = $row;
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => [$a['priority'], 0] <=> [$b['priority'], 0]);

        return (string) $candidates[0]['url_hash'];
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
     * @return array<string, mixed>|null
     */
    private function find(string $hash): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['url_hash'] === $hash) {
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
            if ($existing['url_hash'] === $row['url_hash']) {
                $this->rows[$index] = $row;

                return;
            }
        }
    }
}
