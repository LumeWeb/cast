<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Adapter exposing a WorkItemRepository as the read-only fixed-point state the
 * validation needs, so the queue implementation is reused without
 * being coupled to validation.
 *
 * The queue is shared across runs, so reads are scoped to a single run: the
 * optional resolver names it. Without a resolver the scope is empty, which
 * under-reports pending work rather than crossing runs.
 */
final class RepositoryWorkItemStateProvider implements WorkItemStateProvider
{
    /**
     * @param \Closure(): string $runIdProvider Returns the run id to scope
     *                                          every read to.
     */
    public function __construct(
        private readonly WorkItemRepository $repository,
        ?\Closure $runIdProvider = null,
    ) {
        $this->runIdProvider = $runIdProvider ?? static fn(): string => '';
    }

    /**
     * @var \Closure(): string
     */
    private \Closure $runIdProvider;

    public function hasPending(string $runId = ''): bool
    {
        return $this->repository->hasPending($this->scopedRunId($runId));
    }

    public function countByStatus(string $runId, WorkItemStatus $status): int
    {
        return $this->repository->countByStatus($this->scopedRunId($runId), $status);
    }

    private function scopedRunId(string $runId): string
    {
        return $runId !== '' ? $runId : ($this->runIdProvider)();
    }
}
