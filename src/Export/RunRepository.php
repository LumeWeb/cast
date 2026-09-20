<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Persistence interface for export/publish runs, isolating storage so the WP-Cron
 * tick logic stays testable without a database.
 *
 * The surface is the minimum a tick lifecycle needs: atomically create a run,
 * load it back, persist it after every mutation, discard it, and answer "what
 * is the newest run" so the debounced/squashed scheduler can fold a pending
 * run into the latest record instead of stacking duplicates. The in-memory
 * fake backs unit tests; the WordPress adapter (single-option aggregate,
 * mirroring the onboarding pattern) implements the same contract.
 */
interface RunRepository
{
    public function find(string $runId): ?ExportRun;

    /**
     * Atomically persist a brand-new run and report whether it was created.
     *
     * Returns false (and stores nothing) when a run already exists, so
     * concurrent creators — e.g. two WP-Cron ticks racing to start a run —
     * cannot double-start: the first create wins, later duplicates are
     * rejected.
     */
    public function create(ExportRun $run): bool;

    public function save(ExportRun $run): void;

    public function delete(string $runId): void;

    /**
     * The most recently updated run, or null when none is stored. When
     * timestamps tie, the most recently saved run wins.
     */
    public function latest(): ?ExportRun;

    /**
     * @return list<ExportRun>
     */
    public function list(): array;
}
