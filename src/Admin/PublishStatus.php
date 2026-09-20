<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Environment\EnvProblem;
use LumeWeb\Cast\Export\RunStage;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Jobs\PublishIdentity;
use LumeWeb\Cast\Jobs\PublishMode;

/**
 * Typed, JSON-safe status report for the publish UX surface.
 *
 * Reports environment/bootstrap readiness, onboarding terminal state,
 * publishable-content readiness, the selected publish mode (and whether it is
 * auto-publishing), the current run's status/stage/progress and drift, the
 * resolved publish identity, the registry-first awaiting-website signal and
 * the last actionable error. The serialized form never carries credentials:
 * environment problems serialize only variable
 * names/kinds, and the identity only identifiers.
 */
final class PublishStatus
{
    /**
     * @param list<EnvProblem> $envProblems
     */
    public function __construct(
        public readonly bool $bootstrapIdentityComplete,
        public readonly array $envProblems,
        public readonly bool $onboardingComplete,
        public readonly bool $hasEligibleContent,
        public readonly PublishMode $mode,
        public readonly bool $autoActive,
        public readonly RunStatus $runStatus,
        public readonly RunStage $runStage,
        public readonly int $progressCount,
        /** The fine pipeline boundary the run currently occupies, parsed from
         * the persisted resume cursor prefix (probe|, setup|, discover|,
         * capture|, rewrite|, pack|, wrapup|, publish|), or null before the
         * first tick or when no run exists. The coarse run_stage
         * (Exporting/Uploading/Publishing) cannot express this nuance — e.g.
         * "capture in progress" vs "rewrite in progress" — so the copy layer
         * keys off this instead of inventing a 25% fiction. */
        public readonly ?string $pipelineStage,
        /** The honest full-run denominator: how many distinct work items the
         * run is responsible for — the discover-enqueued URL total
         * (DiscoverResult::enqueued, persisted once discovery drains) PLUS
         * every asset rewrite collected (the cumulative count carried by the
         * rewrite cursor while rewrite runs, and pinned on the rewrite summary
         * once it drains), so reconciliation beyond the discover-only list
         * never lets "x of M" exceed the total. Null before discover finishes
         * and on publish-only re-runs, where no discovery happens at all — the
         * UI falls back to indeterminate stage copy instead of a made-up
         * total. */
        public readonly ?int $progressTotal,
        /** The capture numerator: done + failed + skipped from the persisted
         * CaptureSummary once capture reaches its fixed point, or — while
         * capture is still in flight (and a WorkItemStateProvider is wired) —
         * the same sum read LIVE from the shared item table, so the copy shows
         * an honest, moving "Captured X of M URLs" instead of the stage verb.
         * Null only when neither exists (unwired provider, or cursor before/on
         * another stage). */
        public readonly ?int $captureDone,
        /** The rewrite numerator: rewritten + passedThrough from the persisted
         * RewriteSummary once rewrite reaches its fixed point, or — while
         * rewrite is still in flight (and a WorkItemStateProvider is wired) —
         * the LIVE count of Rewritten rows (the only per-row mark rewrite
         * leaves), so the copy shows an honest "Rewrote X of M" as it goes.
         * Null only when neither exists. */
        public readonly ?int $rewriteDone,
        /** Unix seconds the queued (not-started) run entered the queue, or
         * null when no run is waiting. The client uses this to show how long
         * a publish has been waiting instead of a misleading progress count. */
        public readonly ?int $queuedAt,
        public readonly bool $runActive,
        public readonly bool $dirty,
        public readonly bool $superseded,
        public readonly ?string $publishCid,
        public readonly ?string $lastError,
        public readonly ?PublishIdentity $identity,
        public readonly ?ConnectionView $connection = null,
        /** Registry-first "sent — waiting for a website": the persisted run
         * parked at the publish boundary (resumable, CID preserved) with no
         * local website id, and the account website list contains no website
         * whose target_hash matches the preserved CID. Drives the guided card
         * state; false by default so an ordinary poll carries no
         * website UI signal. */
        public readonly bool $awaitingWebsite = false,
        /** The preserved CID of the parked run while {@see awaitingWebsite} is
         * true, null otherwise. The top-level publish_cid only carries a
         * Completed boundary's CID; a resumable park keeps it on the boundary,
         * so the "sent — waiting for a website" card surfaces it from here
         * instead of mislabeling it as published. */
        public readonly ?string $awaitingCid = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bootstrap_identity_complete' => $this->bootstrapIdentityComplete,
            'env_problems' => array_map(
                static fn (EnvProblem $problem): array => [
                    'variable' => $problem->variable(),
                    'kind' => $problem->kind()->value,
                    'message' => $problem->message(),
                ],
                $this->envProblems,
            ),
            'onboarding_complete' => $this->onboardingComplete,
            'has_eligible_content' => $this->hasEligibleContent,
            'mode' => $this->mode->value,
            'auto_active' => $this->autoActive,
            'run_status' => $this->runStatus->value,
            'run_stage' => $this->runStage->value,
            'progress_count' => $this->progressCount,
            'pipeline_stage' => $this->pipelineStage,
            'progress_total' => $this->progressTotal,
            'capture_done' => $this->captureDone,
            'rewrite_done' => $this->rewriteDone,
            'queued_at' => $this->queuedAt,
            'run_active' => $this->runActive,
            'dirty' => $this->dirty,
            'superseded' => $this->superseded,
            'publish_cid' => $this->publishCid,
            'last_error' => $this->lastError,
            'errors' => $this->lastError === null ? [] : [$this->lastError],
            'identity' => $this->identity === null ? null : [
                'website_id' => $this->identity->websiteId,
                'website_name' => $this->identity->websiteName,
                'ipns_key_id' => $this->identity->ipnsKeyId,
                'ipns_key_name' => $this->identity->ipnsKeyName,
            ],
            // Additive: the lazily-resolved Connection card state (identifiers
            // only — never credentials).
            'connection' => $this->connection?->toArray(),
            'awaiting_website' => $this->awaitingWebsite,
            'awaiting_cid' => $this->awaitingCid,
        ];
    }
}
