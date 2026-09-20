<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

use LumeWeb\Cast\Publish\Artifact;
use LumeWeb\Cast\Publish\PublishOutcome;
use LumeWeb\Cast\Publish\PublishResult;
use LumeWeb\Cast\Publish\PublishService;

/**
 * The publish pipeline boundary: the thinnest possible pipeline stage over
 * the existing {@see PublishService} — it turns the packed artifact the state
 * rehydrated (zip path + file size) plus the run's target type/label into an
 * {@see Artifact}, drives one publish through the service, and reports the
 * outcome as a bounded {@see StageResult} while recording a persisted
 * {@see PublishBoundaryResult} onto the shared state.
 *
 * The stage itself never loads WordPress (the service's clients are injected)
 * and never touches the run aggregate. A publish failure — Failed or Resumable
 * — surfaces as a stage failure so the tick runner retries it; the durable
 * PublishRegistry the service reads makes a retry resume from exactly where
 * the publish stalled.
 */
final class PublishStage implements PipelineStage
{
    public function __construct(
        private readonly PublishService $publish,
        private readonly PipelineState $state,
        private readonly string $targetType,
        private readonly string $label,
    ) {
    }

    public function key(): PipelineStageKey
    {
        return PipelineStageKey::Publish;
    }

    public function execute(string $cursor): StageResult
    {
        if ($this->state->probe === null) {
            return StageResult::fail('Publish requires a successful probe first.');
        }

        $pack = $this->state->pack;
        if ($pack === null) {
            return StageResult::fail('Publish requires a successful pack first.');
        }

        // The artifact may have been written by a different tick; never trust
        // a stale stat cache for the upload evidence.
        clearstatcache(true);
        if (!is_file($pack->zipPath)) {
            $message = 'Artifact ZIP file is missing.';
            $this->state->publish = PublishBoundaryResult::failed($message);

            return StageResult::fail($message);
        }

        $artifact = new Artifact(
            $pack->zipPath,
            basename($pack->zipPath),
            $this->targetType,
            $this->label,
            (int) filesize($pack->zipPath),
        );

        $result = $this->publish->publish($artifact);

        if ($result->outcome === PublishOutcome::Completed) {
            // A completed publish must carry the full identity; the service
            // guarantees it, but a gap here must fail loudly rather than
            // record a half-identity as complete.
            if ($result->cid === null || $result->websiteId === null || $result->ipnsKey === null) {
                $message = 'Publish completed without a full website identity.';
                $this->state->publish = PublishBoundaryResult::failed($message);

                return StageResult::fail($message);
            }

            $this->state->publish = PublishBoundaryResult::completed(
                $result->cid,
                $result->websiteId,
                $result->ipnsKey,
            );

            return StageResult::done('');
        }

        if ($result->outcome === PublishOutcome::Failed) {
            $message = $result->message ?? 'Publish failed';
            $this->state->publish = PublishBoundaryResult::failed($message);

            return StageResult::fail($message);
        }

        // Resumable: the CID and any identity already owned are preserved so
        // the durable-registry retry resumes instead of restarting.
        $message = $result->message ?? 'Publish stalled';
        if ($result->cid === null) {
            // A publish that stalled before producing a CID has nothing to
            // resume from — surface it as a plain failure instead.
            $this->state->publish = PublishBoundaryResult::failed($message);

            return StageResult::fail($message);
        }

        $this->state->publish = PublishBoundaryResult::resumable(
            $result->cid,
            $result->websiteId,
            $result->ipnsKey,
            $message,
        );

        return StageResult::fail($message);
    }
}
