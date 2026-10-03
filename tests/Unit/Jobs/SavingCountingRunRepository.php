<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\InMemoryRunRepository;
use LumeWeb\Cast\Export\RunRepository;

/**
 * RunRepository double that counts every save() so a test can prove the
 * runner persists after EACH bounded unit — invisible with the plain
 * in-memory repository, which stores the aggregate by reference.
 */
final class SavingCountingRunRepository implements RunRepository
{
    public int $saves = 0;

    public function __construct(private readonly InMemoryRunRepository $inner)
    {
    }

    public function find(string $runId): ?ExportRun
    {
        return $this->inner->find($runId);
    }

    public function create(ExportRun $run): bool
    {
        return $this->inner->create($run);
    }

    public function save(ExportRun $run): void
    {
        ++$this->saves;
        $this->inner->save($run);
    }

    public function delete(string $runId): void
    {
        $this->inner->delete($runId);
    }

    public function latest(): ?ExportRun
    {
        return $this->inner->latest();
    }

    /**
     * @return list<ExportRun>
     */
    public function list(): array
    {
        return $this->inner->list();
    }
}
