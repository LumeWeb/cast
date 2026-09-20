<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The single place a captured item's outcome is mapped onto the work-item
 * lifecycle, shared by the capture stage (the page/discovery queue) and the
 * rewrite stage's asset reconciliation so retry budgeting is never duplicated.
 *
 * A retryable outcome stays in the queue (scheduled for a later attempt) until
 * the bounded attempt budget is exhausted, at which point the row is marked
 * Failed as terminal — a failed asset therefore never blocks the convergence
 * of the reconciliation loop. Every other outcome moves straight to its
 * terminal status.
 */
final class CaptureOutcomeApplier
{
    public function apply(WorkItemRepository $repository, string $urlHash, CaptureResult $result): void
    {
        if ($result->outcome->isRetryable()) {
            if ($repository->attemptCountOf($urlHash) >= RetryPolicy::MAX_ATTEMPTS) {
                $repository->transition($urlHash, WorkItemStatus::Failed);
            } else {
                $repository->scheduleRetry($urlHash, $result->retryDelaySeconds);
            }

            return;
        }

        $repository->transition($urlHash, match ($result->outcome) {
            CaptureOutcome::CanonicalTwin, CaptureOutcome::OffOrigin => WorkItemStatus::Skipped,
            CaptureOutcome::Copied, CaptureOutcome::Fetched, CaptureOutcome::Redirected => WorkItemStatus::Done,
            default => WorkItemStatus::Failed,
        });
    }
}
