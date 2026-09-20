<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * In-memory RunRepository used by unit tests and single-process runs.
 *
 * Stores ExportRun aggregates keyed by run id, upserting by id, and resolves
 * `latest` as the run with the greatest updatedAt — preferring the most
 * recently saved on a tie so insertion order breaks equality deterministically.
 * The WordPress adapter can later be verified against the same contract.
 */
final class InMemoryRunRepository implements RunRepository
{
    /**
     * @var array<string, ExportRun>
     */
    private array $runs = [];

    public function find(string $runId): ?ExportRun
    {
        return $this->runs[$runId] ?? null;
    }

    public function create(ExportRun $run): bool
    {
        if (isset($this->runs[$run->runId])) {
            return false;
        }

        $this->runs[$run->runId] = $run;

        return true;
    }

    public function save(ExportRun $run): void
    {
        $this->runs[$run->runId] = $run;
    }

    public function delete(string $runId): void
    {
        unset($this->runs[$runId]);
    }

    public function latest(): ?ExportRun
    {
        $latest = null;
        foreach ($this->runs as $run) {
            if ($latest === null || $run->updatedAt >= $latest->updatedAt) {
                $latest = $run;
            }
        }

        return $latest;
    }

    /**
     * @return list<ExportRun>
     */
    public function list(): array
    {
        return array_values($this->runs);
    }
}
