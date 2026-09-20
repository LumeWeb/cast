<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

use InvalidArgumentException;

/**
 * The export/publish run aggregate: one durable opinion about a single
 * publish attempt, shaped for WP-Cron ticks.
 *
 * The aggregate owns the lifecycle {@see RunStatus} and pipeline {@see RunStage}
 * state machines, the content-readiness flags (dirty / superseded), progress
 * counters, the publish identifiers already produced (CID, website, IPNS key,
 * upload identifier) and the resume metadata (retry count, upload offset,
 * export cursor) a later tick needs to continue exactly where it stopped.
 *
 * Safety rules enforced here:
 *  - every lifecycle move goes through the {@see RunStatus} transition graph
 *    and throws {@see InvalidTransition} when illegal;
 *  - a superseded run (overtaken by new content) may only be cancelled — it
 *    can never start, resume, advance, or publish;
 *  - a finished run is immutable except for readiness/touch bookkeeping:
 *    progress, offsets and identifiers cannot be recorded after a terminal
 *    status;
 *  - warnings block `completeWithWarnings` so a run can never claim warnings
 *    it did not record.
 *
 * The settings snapshot is captured at {@see create()} and never changes.
 */
final class ExportRun
{
    public const SCHEMA_VERSION = 1;

    /**
     * Resume cursor for a publish-only run seeded from an intact existing
     * artifact: the pipeline rehydrates the packed artifact and jumps straight
     * to the publish boundary without re-exporting.
     */
    public const RESUME_PUBLISH_ONLY = 'publish|';

    public RunStatus $status;

    public RunStage $stage;

    public function __construct(
        public readonly string $runId,
        RunStatus $status = RunStatus::NotStarted,
        RunStage $stage = RunStage::Idle,
        public RunSettings $settings = new RunSettings(),
        public int $progressCount = 0,
        public int $createdAt = 0,
        public int $updatedAt = 0,
        public bool $dirty = false,
        public bool $superseded = false,
        /** Whether the run was started by an explicit user action (Publish
         * now / first publish) rather than left for the auto pipeline. An
         * explicit run may begin before a publish identity exists; an
         * auto-armed one waits for identity. */
        public bool $explicitStart = false,
        public int $retryCount = 0,
        public int $warningCount = 0,
        public ?int $lastUploadOffsetBytes = null,
        public string $resumeCursor = '',
        public ?string $lastUploadIdentifier = null,
        public ?string $lastError = null,
        public ?string $lastWarningMessage = null,
        public ?string $publishCid = null,
        public ?string $websiteId = null,
        public ?string $ipnsKey = null,
        public ?int $endedAt = null,
        public ?ProbeResult $probe = null,
        public ?SetupResult $setup = null,
        public ?DiscoverResult $discover = null,
        public ?CaptureSummary $capture = null,
        public ?RewriteSummary $rewrite = null,
        public ?PackResult $pack = null,
        public ?WrapupResult $wrapup = null,
        public ?PublishBoundaryResult $publish = null,
    ) {
        $this->status = $status;
        $this->stage = $stage;
    }

    /**
     * Create a never-started run holding an immutable settings snapshot.
     */
    public static function create(string $runId, RunSettings $settings, ?int $at = null): self
    {
        $now = $at ?? time();

        return new self(
            runId: $runId,
            settings: $settings,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Rebuild a run from persisted data.
     *
     * Missing data, a missing run_id or a missing settings snapshot are
     * rejected loudly so a corrupt row is never silently reinterpreted. The
     * schema_version field is not a compatibility check: no released persisted
     * data exists yet, so a differing or missing version is read as-is and
     * normalised back to {@see self::SCHEMA_VERSION} on serialization.
     *
     * @param mixed $data The raw persisted value.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new InvalidArgumentException('Unrecognised run data.');
        }

        $runId = $data['run_id'] ?? null;
        if (!is_string($runId) || $runId === '') {
            throw new InvalidArgumentException('Run data is missing its run_id.');
        }

        $settings = $data['settings'] ?? null;
        if (!is_array($settings)) {
            throw new InvalidArgumentException('Run data is missing its settings snapshot.');
        }

        /** @var array<string, mixed> $settings */
        $createdAt = $data['created_at'] ?? 0;
        $updatedAt = $data['updated_at'] ?? 0;
        $progress = $data['progress_count'] ?? 0;
        $retryCount = $data['retry_count'] ?? 0;
        $warningCount = $data['warning_count'] ?? 0;
        $offset = $data['last_upload_offset_bytes'] ?? null;
        $cursor = $data['resume_cursor'] ?? '';
        $explicitStart = $data['explicit_start'] ?? false;
        $endedAt = $data['ended_at'] ?? null;
        $uploadIdentifier = $data['last_upload_identifier'] ?? null;
        $error = $data['last_error'] ?? null;
        $warningMessage = $data['last_warning_message'] ?? null;
        $cid = $data['publish_cid'] ?? null;
        $websiteId = $data['website_id'] ?? null;
        $ipnsKey = $data['ipns_key'] ?? null;
        $probe = $data['probe'] ?? null;
        $setup = $data['setup'] ?? null;
        $discover = $data['discover'] ?? null;
        $capture = $data['capture'] ?? null;
        $rewrite = $data['rewrite'] ?? null;
        $pack = $data['pack'] ?? null;
        $wrapup = $data['wrapup'] ?? null;
        $publish = $data['publish'] ?? null;

        return new self(
            runId: $runId,
            status: RunStatus::fromStored($data['status'] ?? null),
            stage: RunStage::fromStored($data['stage'] ?? null),
            settings: RunSettings::fromArray($settings),
            progressCount: is_int($progress) ? $progress : 0,
            createdAt: is_int($createdAt) ? $createdAt : 0,
            updatedAt: is_int($updatedAt) ? $updatedAt : 0,
            dirty: ($data['dirty'] ?? false) === true,
            superseded: ($data['superseded'] ?? false) === true,
            explicitStart: $explicitStart === true,
            retryCount: is_int($retryCount) ? $retryCount : 0,
            warningCount: is_int($warningCount) ? $warningCount : 0,
            lastUploadOffsetBytes: is_int($offset) ? $offset : null,
            resumeCursor: is_string($cursor) ? $cursor : '',
            lastUploadIdentifier: is_string($uploadIdentifier) ? $uploadIdentifier : null,
            lastError: is_string($error) ? $error : null,
            lastWarningMessage: is_string($warningMessage) ? $warningMessage : null,
            publishCid: is_string($cid) ? $cid : null,
            websiteId: is_string($websiteId) ? $websiteId : null,
            ipnsKey: is_string($ipnsKey) ? $ipnsKey : null,
            endedAt: is_int($endedAt) ? $endedAt : null,
            probe: $probe === null ? null : ProbeResult::fromArray($probe),
            setup: $setup === null ? null : SetupResult::fromArray($setup),
            discover: $discover === null ? null : DiscoverResult::fromArray($discover),
            capture: $capture === null ? null : CaptureSummary::fromArray($capture),
            rewrite: $rewrite === null ? null : RewriteSummary::fromArray($rewrite),
            pack: $pack === null ? null : PackResult::fromArray($pack),
            wrapup: $wrapup === null ? null : WrapupResult::fromArray($wrapup),
            publish: $publish === null ? null : PublishBoundaryResult::fromArray($publish),
        );
    }

    /**
     * Explicit, validated serialization for a persisted run.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $this->runId,
            'status' => $this->status->value,
            'stage' => $this->stage->value,
            'settings' => $this->settings->toArray(),
            'progress_count' => $this->progressCount,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'dirty' => $this->dirty,
            'superseded' => $this->superseded,
            'explicit_start' => $this->explicitStart,
            'retry_count' => $this->retryCount,
            'warning_count' => $this->warningCount,
            'last_upload_offset_bytes' => $this->lastUploadOffsetBytes,
            'resume_cursor' => $this->resumeCursor,
            'last_upload_identifier' => $this->lastUploadIdentifier,
            'last_error' => $this->lastError,
            'last_warning_message' => $this->lastWarningMessage,
            'publish_cid' => $this->publishCid,
            'website_id' => $this->websiteId,
            'ipns_key' => $this->ipnsKey,
            'ended_at' => $this->endedAt,
            'probe' => $this->probe?->toArray(),
            'setup' => $this->setup?->toArray(),
            'discover' => $this->discover?->toArray(),
            'capture' => $this->capture?->toArray(),
            'rewrite' => $this->rewrite?->toArray(),
            'pack' => $this->pack?->toArray(),
            'wrapup' => $this->wrapup?->toArray(),
            'publish' => $this->publish?->toArray(),
        ];
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function retriesRemaining(): int
    {
        return max(0, $this->settings->maxRetries - $this->retryCount);
    }

    public function retriesExhausted(): bool
    {
        return $this->retriesRemaining() === 0;
    }

    /**
     * The immutable settings snapshot as a defensive array copy.
     *
     * @return array{
     *     target_type: string,
     *     hostname: string,
     *     artifact_name: string,
     *     upload_limit_bytes: int,
     *     max_retries: int,
     *     start_cursor: string
     * }
     */
    public function settingsSnapshot(): array
    {
        return $this->settings->toArray();
    }

    public function start(?int $at = null): void
    {
        $this->transition(RunStatus::Running, $at);
    }

    public function pause(?int $at = null): void
    {
        $this->transition(RunStatus::Paused, $at);
    }

    public function resume(?int $at = null): void
    {
        $this->transition(RunStatus::Running, $at);
    }

    public function complete(?int $at = null): void
    {
        $this->transition(RunStatus::Completed, $at);
        $this->finish($at);
    }

    public function completeWithWarnings(?int $at = null): void
    {
        if ($this->warningCount === 0) {
            throw new InvalidTransition(sprintf(
                'Run "%s" has no warnings recorded, cannot complete with warnings.',
                $this->runId,
            ));
        }

        $this->transition(RunStatus::CompletedWithWarnings, $at);
        $this->finish($at);
    }

    public function fail(string $reason, ?int $at = null): void
    {
        $this->transition(RunStatus::Failed, $at);
        $this->lastError = $reason;
        $this->finish($at);
    }

    public function cancel(?int $at = null): void
    {
        $this->transition(RunStatus::Cancelled, $at);
        $this->finish($at);
    }

    public function advanceStage(RunStage $stage, ?int $at = null): void
    {
        if ($this->status !== RunStatus::Running) {
            throw new InvalidTransition(sprintf(
                'Cannot advance stage of run "%s" while %s.',
                $this->runId,
                $this->status->value,
            ));
        }

        if ($this->superseded) {
            throw new InvalidTransition(sprintf(
                'Superseded run "%s" may only be cancelled.',
                $this->runId,
            ));
        }

        if (!$this->stage->canAdvanceTo($stage)) {
            throw new InvalidTransition(sprintf(
                'Cannot move run "%s" stage from %s to %s.',
                $this->runId,
                $this->stage->value,
                $stage->value,
            ));
        }

        $this->stage = $stage;
        $this->touch($at);
    }

    /**
     * Record that the run covers content earlier than the current live state.
     *
     * Allowed from any status — a finished run can become dirty again when new
     * content arrives after publication.
     */
    public function markDirty(?int $at = null): void
    {
        $this->dirty = true;
        $this->touch($at);
    }

    /**
     * Mark the run as explicitly started by the user (Publish now / first
     * publish), so a later tick may begin it even before a publish identity
     * exists. The auto pipeline never sets this — a pending auto run keeps
     * waiting (deferred) for identity instead.
     */
    public function markExplicitStart(?int $at = null): void
    {
        $this->explicitStart = true;
        $this->touch($at);
    }

    /**
     * Mark the run as overtaken by newer content (its snapshot is stale).
     *
     * Only legal while not terminal: a finished run cannot be superseded.
     */
    public function supersede(?int $at = null): void
    {
        if ($this->status->isTerminal()) {
            throw new InvalidTransition(sprintf(
                'Run "%s" already finished, cannot supersede.',
                $this->runId,
            ));
        }

        $this->superseded = true;
        $this->touch($at);
    }

    /**
     * Content changed somewhere on the site: record the drift and, when the
     * run is still live, supersede it because its snapshot is stale.
     */
    public function notifyContentChanged(?int $at = null): void
    {
        $this->dirty = true;

        if (!$this->status->isTerminal()) {
            $this->superseded = true;
        }

        $this->touch($at);
    }

    public function recordProgress(int $count, ?int $at = null): void
    {
        if ($count < 0) {
            throw new InvalidArgumentException('Progress count must be non-negative.');
        }

        $this->assertNotFinished('record progress');
        $this->progressCount = $count;
        $this->touch($at);
    }

    /**
     * Record one retry of the current step. Throws once the budget (frozen in
     * the settings snapshot) is exhausted — the tick must then fail the run.
     */
    public function recordRetry(?int $at = null): void
    {
        if ($this->status !== RunStatus::Running) {
            throw new InvalidTransition(sprintf(
                'Cannot record a retry for run "%s" while %s.',
                $this->runId,
                $this->status->value,
            ));
        }

        if ($this->retriesRemaining() === 0) {
            throw new InvalidTransition(sprintf(
                'Retry budget (%d) exhausted for run "%s".',
                $this->settings->maxRetries,
                $this->runId,
            ));
        }

        ++$this->retryCount;
        $this->touch($at);
    }

    public function recordUploadOffset(int $bytes, ?int $at = null): void
    {
        if ($bytes < 0) {
            throw new InvalidArgumentException('Upload offset must be non-negative.');
        }

        $this->assertNotFinished('record an upload offset');
        $this->lastUploadOffsetBytes = $bytes;
        $this->touch($at);
    }

    public function recordResumeCursor(string $cursor, ?int $at = null): void
    {
        $this->resumeCursor = $cursor;
        $this->touch($at);
    }

    public function recordWarning(string $message = '', ?int $at = null): void
    {
        $this->assertNotFinished('record a warning');
        ++$this->warningCount;
        if ($message !== '') {
            $this->lastWarningMessage = $message;
        }
        $this->touch($at);
    }

    public function recordUploadIdentifier(string $identifier, ?int $at = null): void
    {
        $this->assertNotFinished('record an upload identifier');
        $this->lastUploadIdentifier = $identifier;
        $this->touch($at);
    }

    public function recordPublishIdentifiers(string $cid, string $websiteId, string $ipnsKey, ?int $at = null): void
    {
        $this->assertNotFinished('record publish identifiers');
        $this->publishCid = $cid;
        $this->websiteId = $websiteId;
        $this->ipnsKey = $ipnsKey;
        $this->touch($at);
    }

    /**
     * Persist the Probe stage's runtime state so a later WP-Cron tick can
     * rehydrate the shared PipelineState instead of re-running the probe.
     */
    public function recordProbe(ProbeResult $probe, ?int $at = null): void
    {
        $this->assertNotFinished('record probe result');
        $this->probe = $probe;
        $this->touch($at);
    }

    /**
     * Persist the Setup stage's runtime state (the jailed work directory) so a
     * later WP-Cron tick reuses it instead of re-creating it.
     */
    public function recordSetup(SetupResult $setup, ?int $at = null): void
    {
        $this->assertNotFinished('record setup result');
        $this->setup = $setup;
        $this->touch($at);
    }

    /**
     * Persist the Discover stage's runtime state (how many distinct work items
     * were enqueued) so a later WP-Cron tick rehydrates the shared
     * PipelineState instead of re-running discovery.
     */
    public function recordDiscover(DiscoverResult $discover, ?int $at = null): void
    {
        $this->assertNotFinished('record discover result');
        $this->discover = $discover;
        $this->touch($at);
    }

    /**
     * Persist the Capture stage's runtime state (how many rows terminally
     * landed as done, failed and skipped) so a later WP-Cron tick rehydrates
     * the shared PipelineState instead of re-running capture.
     */
    public function recordCapture(CaptureSummary $capture, ?int $at = null): void
    {
        $this->assertNotFinished('record capture result');
        $this->capture = $capture;
        $this->touch($at);
    }

    /**
     * Persist the Rewrite stage's runtime state (how many rows were rewritten
     * and how many binary/fixed items passed through) so a later WP-Cron tick
     * rehydrates the shared PipelineState instead of re-running rewrite.
     */
    public function recordRewrite(RewriteSummary $rewrite, ?int $at = null): void
    {
        $this->assertNotFinished('record rewrite result');
        $this->rewrite = $rewrite;
        $this->touch($at);
    }

    /**
     * Persist the Pack stage's runtime state (the produced ZIP, its adjacent
     * manifest and every pre-pack warning) so a later WP-Cron tick rehydrates
     * the shared PipelineState instead of re-packing.
     */
    public function recordPack(PackResult $pack, ?int $at = null): void
    {
        $this->assertNotFinished('record pack result');
        $this->pack = $pack;
        $this->touch($at);
    }

    /**
     * Persist the Wrapup stage's runtime state (the outcome, work-directory
     * deletion flag and any integrity/deletion details) so a later WP-Cron
     * tick rehydrates the shared PipelineState instead of re-running wrap-up.
     */
    public function recordWrapup(WrapupResult $wrapup, ?int $at = null): void
    {
        $this->assertNotFinished('record wrapup result');
        $this->wrapup = $wrapup;
        $this->touch($at);
    }

    /**
     * Persist the Publish stage's runtime state (the completed/failed/
     * resumable boundary outcome and the CID/website/IPNS identity produced so
     * far) so a later WP-Cron tick rehydrates the shared PipelineState instead
     * of re-publishing.
     */
    public function recordPublishBoundary(PublishBoundaryResult $publish, ?int $at = null): void
    {
        $this->assertNotFinished('record publish boundary result');
        $this->publish = $publish;
        $this->touch($at);
    }

    public function touch(?int $at = null): void
    {
        $this->updatedAt = $at ?? time();
    }

    /**
     * Validate and apply one lifecycle move through the run status graph.
     *
     * A superseded run may only reach Cancelled; every other move is refused.
     */
    private function transition(RunStatus $target, ?int $at): void
    {
        if ($this->superseded && $target !== RunStatus::Cancelled) {
            throw new InvalidTransition(sprintf(
                'Superseded run "%s" may only be cancelled.',
                $this->runId,
            ));
        }

        if (!$this->status->canTransitionTo($target)) {
            throw new InvalidTransition(sprintf(
                'Cannot transition run "%s" from %s to %s.',
                $this->runId,
                $this->status->value,
                $target->value,
            ));
        }

        $this->status = $target;
        $this->touch($at);
    }

    private function finish(?int $at): void
    {
        $this->stage = RunStage::Finished;
        $this->endedAt ??= $at ?? time();
    }

    private function assertNotFinished(string $action): void
    {
        if ($this->status->isTerminal()) {
            throw new InvalidTransition(sprintf(
                'Cannot %s on finished run "%s".',
                $action,
                $this->runId,
            ));
        }
    }
}
