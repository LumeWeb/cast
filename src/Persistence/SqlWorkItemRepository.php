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
 * Durable SQL-backed {@see WorkItemRepository} over the shared
 * `cast_export_items` table.
 *
 * The table (`{prefix}cast_export_items`, see {@see CastExportItemsTable})
 * gives the crawler what the in-memory fake cannot: a URL-hash unique index,
 * integer retry_at gating, and atomic conditional claims so a single queue
 * feeds capture from many WP-Cron requests. The insert semantics mirror the
 * in-memory contract exactly — first successful item wins forever, and a lower
 * numeric priority may replace the stored priority without resetting status —
 * while {@see claimNext()} claims with a conditional `UPDATE` guarded by
 * `status = 'queued'`, so a racing worker cannot double-claim a row.
 *
 * All SQL goes through the injected {@see WpDbGateway} adapter with bound
 * placeholders; the table identifier is embedded at construction from a trusted
 * name (never user input), exactly like wpdb requires.
 */
final class SqlWorkItemRepository implements WorkItemRepository
{
    // Statement templates; {table} is substituted at query time, the %s / %d
    // placeholders map 1:1 to the bound params passed to the gateway.
    private const INSERT = 'INSERT INTO {table} (url_hash, url, identity, path, kind, priority, status, fetch_attempts, retry_at) VALUES (%s, %s, %s, %s, %s, %d, %s, %d, %d)';
    private const SELECT_PRIORITY = 'SELECT priority FROM {table} WHERE url_hash = %s';
    private const SELECT_ATTEMPTS = 'SELECT fetch_attempts FROM {table} WHERE url_hash = %s';
    private const SELECT_COUNT_STATUS = 'SELECT COUNT(*) FROM {table} WHERE status = %s';
    private const SELECT_COUNT_PENDING = 'SELECT COUNT(*) FROM {table} WHERE status IN (%s, %s)';
    private const SELECT_ROW = 'SELECT url_hash, url, identity, path, kind, priority, status, fetch_attempts, retry_at FROM {table} WHERE url_hash = %s';
    private const SELECT_CLAIM = "SELECT url_hash FROM {table} WHERE status = %s AND retry_at <= %d ORDER BY priority ASC, id ASC LIMIT 1";
    private const UPDATE_PRIORITY = 'UPDATE {table} SET priority = %d WHERE url_hash = %s';
    private const UPDATE_TRANSITION = 'UPDATE {table} SET status = %s WHERE url_hash = %s';
    private const UPDATE_RETRY = 'UPDATE {table} SET status = %s, retry_at = %d WHERE url_hash = %s';
    private const UPDATE_CLAIM = "UPDATE {table} SET status = %s, fetch_attempts = fetch_attempts + 1 WHERE url_hash = %s AND status = %s";
    private const DELETE_ALL = 'DELETE FROM {table}';
    private const SELECT_FIRST_REWRITABLE = "SELECT url_hash, url, identity, path, kind, priority, status, fetch_attempts, retry_at FROM {table} WHERE status = %s AND (kind IN (%s, %s, %s) OR (kind = %s AND LOWER(SUBSTRING_INDEX(path, '.', -1)) IN (%s, %s, %s, %s, %s, %s, %s))) ORDER BY priority ASC, id ASC LIMIT 1";

    /**
     * Bound values for {@see self::SELECT_FIRST_REWRITABLE}: the claim status
     * first, then the rewritable kinds plus the text-capable asset extensions,
     * kept in step with {@see \LumeWeb\Cast\Export\InMemoryWorkItemRepository}.
     * The statement's first placeholder is the status guard (`WHERE status =
     * %s`), so the `done` status must lead the rest of the bound values.
     */
    private const REWRITABLE = ['done', 'page', 'redirect', 'text', 'asset', 'css', 'js', 'mjs', 'json', 'xml', 'rss', 'atom'];

    /**
     * How many claim candidates may be stolen by a racing worker before the
     * caller gives up this tick; a bounded retry to stay alive under load.
     */
    private const CLAIM_RETRIES = 5;

    /**
     * @var \Closure(): int
     */
    private readonly \Closure $clock;

    public function __construct(
        private readonly WpDbGateway $db,
        private readonly string $table,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function insertCanonical(WorkItem $workItem, int $priority = 10): InsertOutcome
    {
        if ($priority < 0) {
            $priority = 0;
        }

        $hash = $workItem->urlHash();
        $row = $this->row($hash);

        if ($row === null) {
            $affected = $this->db->query($this->sql(self::INSERT), [
                $hash,
                (string) $workItem->url(),
                $workItem->identity(),
                $workItem->outputPath(),
                $workItem->kind()->value,
                $priority,
                WorkItemStatus::Queued->value,
                0,
                0,
            ]);

            if ($affected === 1) {
                return InsertOutcome::created();
            }

            // A racing writer inserted the same url_hash between our read and
            // write; re-read and apply the duplicate rules below.
            $row = $this->row($hash);
            if ($row === null) {
                return InsertOutcome::duplicate();
            }
        }

        if ($row['status'] === WorkItemStatus::Done->value) {
            return InsertOutcome::duplicate();
        }

        if ($priority < (int) $row['priority']) {
            $this->db->query($this->sql(self::UPDATE_PRIORITY), [$priority, $hash]);

            return InsertOutcome::priorityReplaced();
        }

        return InsertOutcome::duplicate();
    }

    public function priorityOf(string $urlHash): ?int
    {
        $priority = $this->db->getVar($this->sql(self::SELECT_PRIORITY), [$urlHash]);

        return $priority === null ? null : (int) $priority;
    }

    public function countByStatus(WorkItemStatus $status): int
    {
        return (int) $this->db->getVar($this->sql(self::SELECT_COUNT_STATUS), [$status->value]);
    }

    public function pendingCount(): int
    {
        return (int) $this->db->getVar($this->sql(self::SELECT_COUNT_PENDING), [
            WorkItemStatus::Queued->value,
            WorkItemStatus::Processing->value,
        ]);
    }

    public function hasPending(): bool
    {
        return $this->pendingCount() > 0;
    }

    /**
     * Returns true when the item exists — its status is now the requested
     * status — and false only when the hash is unknown.
     *
     * MariaDB reports 0 affected rows when an UPDATE sets a value equal to the
     * stored one, so a same-status transition would otherwise look like a
     * miss; the existence re-check disambiguates the unchanged-but-present
     * case from an unknown hash.
     */
    public function transition(string $urlHash, WorkItemStatus $status): bool
    {
        $affected = $this->db->query($this->sql(self::UPDATE_TRANSITION), [$status->value, $urlHash]);

        return $affected === 1 || $this->row($urlHash) !== null;
    }

    public function claimNext(): ?WorkItem
    {
        $now = ($this->clock)();

        for ($attempt = 0; $attempt < self::CLAIM_RETRIES; ++$attempt) {
            $candidate = $this->db->getVar($this->sql(self::SELECT_CLAIM), [
                WorkItemStatus::Queued->value,
                $now,
            ]);
            if ($candidate === null) {
                return null;
            }

            $hash = (string) $candidate;

            // Exclusive claim: only the worker whose UPDATE still sees the row
            // as queued increments attempts and takes processing.
            $affected = $this->db->query($this->sql(self::UPDATE_CLAIM), [
                WorkItemStatus::Processing->value,
                $hash,
                WorkItemStatus::Queued->value,
            ]);
            if ($affected !== 1) {
                continue;
            }

            $row = $this->row($hash);
            if ($row === null) {
                // The claim succeeded but the row vanished (e.g. a hard delete
                // from another worker); treat it like a lost race and retry.
                continue;
            }

            return $this->hydrate($row);
        }

        return null;
    }

    public function attemptCountOf(string $urlHash): int
    {
        $attempts = $this->db->getVar($this->sql(self::SELECT_ATTEMPTS), [$urlHash]);

        return $attempts === null ? 0 : (int) $attempts;
    }

    /**
     * Returns true when the item exists — the row is re-queued with the new
     * retry time — and false only when the hash is unknown.
     *
     * The same idempotency nuance as {@see self::transition()} applies: MariaDB
     * reports 0 affected rows when the re-queued status and retry time already
     * match the stored values, so the existence re-check disambiguates that
     * unchanged-but-present case from an unknown hash.
     */
    public function scheduleRetry(string $urlHash, int $delaySeconds): bool
    {
        $retryAt = ($this->clock)() + max(0, $delaySeconds);

        $affected = $this->db->query($this->sql(self::UPDATE_RETRY), [
            WorkItemStatus::Queued->value,
            $retryAt,
            $urlHash,
        ]);

        return $affected === 1 || $this->row($urlHash) !== null;
    }

    public function claimNextRewritable(): ?WorkItem
    {
        $row = $this->db->getRow($this->sql(self::SELECT_FIRST_REWRITABLE), self::REWRITABLE);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Remove every row from the queue so a brand-new run starts from an empty
     * table. The queue is per-run scratch space: the next run's discovery
     * refills it, and the prior run's terminal rows (`done`/`rewritten`) must
     * not survive — insertCanonical is first-seen-wins and never re-queues a
     * finished row, so a stale row would starve the fresh per-run work directory.
     * The DELETE is a native prepared statement over the trusted table
     * identifier, exactly like every other query in this repository.
     */
    public function clear(): void
    {
        $this->db->query($this->sql(self::DELETE_ALL));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $urlHash): ?array
    {
        return $this->db->getRow($this->sql(self::SELECT_ROW), [$urlHash]);
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

    /**
     * Substitute the trusted table identifier into a statement template. The
     * identifier is embedded here, never bound, exactly like wpdb requires;
     * the %s / %d parameter placeholders are left intact for the gateway to
     * bind.
     */
    private function sql(string $template): string
    {
        return str_replace('{table}', $this->table, $template);
    }
}
