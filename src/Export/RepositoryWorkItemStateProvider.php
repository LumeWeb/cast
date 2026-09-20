<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Adapter exposing a WorkItemRepository as the read-only fixed-point state the
 * validation needs, so the queue implementation is reused without
 * being coupled to validation.
 */
final class RepositoryWorkItemStateProvider implements WorkItemStateProvider
{
    public function __construct(private readonly WorkItemRepository $repository)
    {
    }

    public function hasPending(): bool
    {
        return $this->repository->hasPending();
    }

    public function countByStatus(WorkItemStatus $status): int
    {
        return $this->repository->countByStatus($status);
    }
}
