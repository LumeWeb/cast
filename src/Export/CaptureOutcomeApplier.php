<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The single place a captured item's outcome is mapped onto the work-item
 * lifecycle, shared by the capture stage (the page/discovery queue) and the
 * rewrite stage's asset reconciliation so retry budgeting is never duplicated.
 *
 * A retryable outcome stays in the queue (scheduled for a later attempt) until
 * the combined attempt budget is exhausted, at which point the row is marked
 * Failed as terminal — a failed asset therefore never blocks the convergence
 * of the reconciliation loop. Every other outcome moves straight to its
 * terminal status.
 *
 * The budget is shared with the capture service's inner retry loop: attempts
 * already spent inside one capture() run (`CaptureResult::attempts`) are
 * counted together with the prior queue claims (`attemptCountOf`) so a
 * persistently retryable item is never fetched more than MAX_ATTEMPTS times
 * in total across both layers.
 *
 * A same-origin redirect of a non-Page item carries no captured content (no
 * stub is written), so its resolved redirect target is enqueued as its own
 * work item here: the mirror keeps the asset by capturing the target itself
 * instead of marking the item Done with a silently vanished file.
 */
final class CaptureOutcomeApplier
{
    public function __construct(
        private readonly WorkItemFactory $workItemFactory = new WorkItemFactory(),
    ) {
    }

    public function apply(WorkItemRepository $repository, WorkItem $item, CaptureResult $result): void
    {
        $urlHash = $item->urlHash();

        if ($result->outcome->isRetryable()) {
            if ($repository->attemptCountOf($urlHash) + $result->attempts >= RetryPolicy::MAX_ATTEMPTS) {
                $repository->transition($urlHash, WorkItemStatus::Failed);
            } else {
                $repository->scheduleRetry($urlHash, $result->retryDelaySeconds);
            }

            return;
        }

        $repository->transition($urlHash, match ($result->outcome) {
            CaptureOutcome::CanonicalTwin, CaptureOutcome::OffOrigin => WorkItemStatus::Skipped,
            CaptureOutcome::Copied, CaptureOutcome::Fetched => WorkItemStatus::Done,
            CaptureOutcome::Redirected => $this->redirected($repository, $item, $result),
            default => WorkItemStatus::Failed,
        });
    }

    private function redirected(WorkItemRepository $repository, WorkItem $item, CaptureResult $result): WorkItemStatus
    {
        // A non-Page same-origin redirect writes no stub (and no file), so the
        // resolved target is queued as a fresh work item for a later capture;
        // the source row still lands Done with nothing written for it.
        if ($item->kind() !== WorkItemKind::Page && $result->redirectTarget !== null) {
            $repository->insertCanonical($this->workItemFactory->fromString($result->redirectTarget));
        }

        return WorkItemStatus::Done;
    }
}
