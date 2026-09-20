<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Environment\EnvProblem;
use LumeWeb\Cast\Export\RunStage;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Jobs\PublishMode;
use LumeWeb\Cast\Jobs\TickConfig;

/**
 * The publish dashboard view model — a pure mapping from the JSON-safe
 * {@see PublishStatus} report to the display/action state the template renders.
 *
 * Every decision that the template or scripts would otherwise re-derive from
 * raw status fields (which card to show, what label and progress, which actions
 * may be offered) is pinned here as a deliberate, tested mapping. The view
 * model holds no WordPress state and performs no side effects; the template
 * only iterates and escapes it.
 */
final class PublishDashboardView
{
    public const READINESS_READY = 'ready';
    public const READINESS_CONFIG = 'config';
    public const READINESS_SETUP = 'setup';
    public const READINESS_NO_CONTENT = 'no_content';

    const RUN_STATE_IDLE = 'idle';
    const RUN_STATE_QUEUED = 'queued';
    const RUN_STATE_RUNNING = 'running';
    const RUN_STATE_PAUSED = 'paused';
    const RUN_STATE_COMPLETED = 'completed';
    const RUN_STATE_COMPLETED_WITH_WARNINGS = 'completed_with_warnings';
    const RUN_STATE_FAILED = 'failed';
    const RUN_STATE_CANCELLED = 'cancelled';

    const MODE_MANUAL = 'manual';
    const MODE_ON_UPDATE = 'on_update';

    public function __construct(
        public readonly string $readiness,
        public readonly string $readinessLevel,
        public readonly string $runState,
        /** The explicit live status an editor can read at a glance ('ready', 'queued — starting shortly', 'publishing', 'published', 'failed — retry available', 'cancelled — retry available', 'paused'). */
        public readonly string $stateLabel,
        public readonly string $runLabel,
        public readonly ?string $stageLabel,
        public readonly int $progressPercent,
        public readonly int $progressCount,
        /** The fine pipeline boundary the run occupies (probe|setup|discover|
         * capture|rewrite|pack|wrapup|publish), passed through from the status
         * report and rendered as a data hook when known. Mirrors the coarse
         * percent balloon while giving the copy a per-stage denominator. */
        public readonly ?string $pipelineStage,
        /** The honest full-run denominator — discover-enqueued URLs plus every
         * asset rewrite collected (see PublishStatus::$progressTotal) — passed
         * through for the template's data-cast-progress-total hook and for the
         * client's live x-of-M mirror. Null before discovery drains / on
         * publish-only re-runs. */
        public readonly ?int $progressTotal,
        /** The secondary progress line's end-user copy: the fine-stage verb
         * plus the honest x-of-M total once discovery has a denominator —
         * e.g. "Captured X of M URLs", "Building the export (M URLs)" — never
         * the misleadingly-large cumulative "items processed" that counts a
         * URL once per pipeline pass. For a queued run it is an honest
         * "Waiting to begin". */
        public readonly string $progressCountLabel,
        /** Unix seconds the queued run entered the queue (null otherwise),
         * passthrough for the client's live elapsed context. */
        public readonly ?int $queuedAt,
        /** The honest queued ETA line, e.g. 'Starting within 30 seconds'
         * (null unless a run is actually queued with a queue-entry time). */
        public readonly ?string $queuedEtaLabel,
        /** Unix seconds the queued run is expected to BEGIN: queuedAt plus the
         * tick delivery cadence (null otherwise). Both server label and the
         * client's live countdown reconcile against this single anchor. */
        public readonly ?int $queuedEtaStartAt,
        /** Whether a queued run may be kicked immediately by the user — the
         * recovery/start-now escape shown after a reasonable wait. */
        public readonly bool $canStartNowEscape,
        /** The REST publish action the escape fires ('start' first publish |
         * 'now' re-publish | null), reusing the existing manual-run-wins
         * routes so a queued run is absorbed and ticked immediately. */
        public readonly ?string $escapeAction,
        public readonly ?string $publishCid,
        public readonly ?string $websiteName,
        public readonly ?string $lastError,
        public readonly string $mode,
        public readonly bool $autoActive,
        public readonly bool $dirty,
        public readonly bool $superseded,
        /** @var list<array{variable: string, kind: string, message: string}> */
        public readonly array $envProblems,
        public readonly bool $canStart,
        public readonly bool $canPublishNow,
        public readonly bool $canCancel,
        public readonly bool $canPublishArtifact,
        /** The prominent "Publish to Pinner" action to offer: 'start'|'now'|null. */
        public readonly ?string $primaryAction,
        /** Whether publishing is actually warranted right now. */
        public readonly bool $needsPublish,
        /** Why publishing is needed (or why it isn't available) — the workflow copy. */
        public readonly string $contextMessage,
        /** Whether the mode selector may be offered (ready surface, not mid-run). */
        public readonly bool $canChangeMode,
        public readonly PublishAdminBarView $adminBar,
        public readonly ?ConnectionView $connection = null,
        /** Registry-first "sent — waiting for a website" passthrough from the
         * status report, which the guided website card rendering keys off.
         * Held here (default false) so the server-rendered template and the
         * no-refresh JS see the same signal while a neutral default keeps an
         * ordinary poll visually unchanged. */
        public readonly bool $awaitingWebsite = false,
        /** The preserved CID of the parked run while {@see awaitingWebsite} is
         * true (null otherwise), quoted by the "sent — waiting for a website"
         * card so the operator can match it in Pinner. The top-level
         * {@see publishCid} stays null for a resumable park, so this is the
         * only place the S1 card reads it. */
        public readonly ?string $websiteCid = null,
    ) {
    }

    public static function fromStatus(PublishStatus $status): self
    {
        $runState = self::runStateFor($status);
        $stageLabel = self::stageLabelFor($status->runStage);
        $readiness = self::readinessFor($status);
        $adminBar = PublishAdminBarView::fromStatus($status);

        return new self(
            readiness: $readiness[0],
            readinessLevel: $readiness[1],
            runState: $runState,
            stateLabel: self::stateLabelFor($runState, $status->awaitingWebsite),
            runLabel: self::runLabelFor($runState, $stageLabel),
            stageLabel: $stageLabel,
            // A parked run awaiting a website is not progressing: the bar and
            // value render at 0 (the template hides them anyway) so no fake
            // percentage brands the parked waiting copy.
            progressPercent: $status->awaitingWebsite ? 0 : self::progressPercentFor($status->runStage),
            progressCount: $status->progressCount,
            pipelineStage: $status->pipelineStage,
            progressTotal: $status->progressTotal,
            progressCountLabel: self::progressCountLabelFor(
                $runState,
                $status->progressCount,
                $status->queuedAt,
                $status->pipelineStage,
                $status->progressTotal,
                $status->captureDone,
                $status->rewriteDone,
                $status->runStage,
                $status->awaitingWebsite,
            ),
            queuedAt: $status->queuedAt,
            queuedEtaLabel: self::queuedEtaLabelFor($runState, $status->queuedAt),
            queuedEtaStartAt: self::queuedEtaStartAtFor($runState, $status->queuedAt),
            canStartNowEscape: self::canStartNowEscape($status, $runState),
            escapeAction: self::escapeActionFor($status, $runState),
            publishCid: $status->publishCid,
            websiteName: $status->identity?->websiteName,
            lastError: $status->lastError,
            mode: $status->mode->value,
            autoActive: $status->mode->isAutomatic(),
            dirty: $status->dirty,
            superseded: $status->superseded,
            envProblems: array_map(
                static fn (EnvProblem $problem): array => [
                    'variable' => $problem->variable(),
                    'kind' => $problem->kind()->value,
                    'message' => $problem->message(),
                ],
                $status->envProblems,
            ),
            canStart: self::canStart($status, $runState, $readiness[0]),
            canPublishNow: self::canPublishNow($status, $runState, $readiness[0]),
            canCancel: in_array($runState, [self::RUN_STATE_QUEUED, self::RUN_STATE_RUNNING, self::RUN_STATE_PAUSED], true),
            canPublishArtifact: self::canPublishArtifact($status, $runState, $readiness[0]),
            primaryAction: self::primaryActionFor($status, $runState, $readiness[0]),
            needsPublish: self::needsPublishFor($status, $runState, $readiness[0]),
            contextMessage: self::contextMessageFor($status, $runState, $readiness[0], $status->awaitingWebsite),
            canChangeMode: $readiness[0] === self::READINESS_READY
                && !in_array($runState, [self::RUN_STATE_QUEUED, self::RUN_STATE_RUNNING, self::RUN_STATE_PAUSED], true),
            adminBar: $adminBar,
            connection: $status->connection,
            awaitingWebsite: $status->awaitingWebsite,
            websiteCid: $status->awaitingCid,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function readinessFor(PublishStatus $status): array
    {
        if (!$status->bootstrapIdentityComplete || $status->envProblems !== []) {
            return [self::READINESS_CONFIG, 'error'];
        }

        if (!$status->onboardingComplete) {
            return [self::READINESS_SETUP, 'warning'];
        }

        if (!$status->hasEligibleContent) {
            return [self::READINESS_NO_CONTENT, 'note'];
        }

        return [self::READINESS_READY, 'ok'];
    }

    /**
     * @return 'idle'|'queued'|'running'|'paused'|'completed'|'completed_with_warnings'|'failed'|'cancelled'
     */
    private static function runStateFor(PublishStatus $status): string
    {
        return match ($status->runStatus) {
            RunStatus::NotStarted => $status->runActive ? self::RUN_STATE_QUEUED : self::RUN_STATE_IDLE,
            RunStatus::Running => self::RUN_STATE_RUNNING,
            RunStatus::Paused => self::RUN_STATE_PAUSED,
            RunStatus::Completed => self::RUN_STATE_COMPLETED,
            RunStatus::CompletedWithWarnings => self::RUN_STATE_COMPLETED_WITH_WARNINGS,
            RunStatus::Failed => self::RUN_STATE_FAILED,
            RunStatus::Cancelled => self::RUN_STATE_CANCELLED,
        };
    }

    /**
     * The explicit queue/run status line the UI leads with. Unlike the run
     * label (which names the stage mid-publish), this is the single word an
     * editor can read at a glance and the text a screen reader hears when the
     * poll livens the chip: ready, queued (starting shortly — deliberately
     * plain, never the jargon-esque "waiting for the worker"), publishing,
     * paused, published, failed/retry and cancelled/retry.
     *
     * A parked first publish awaiting a website (see
     * {@see PublishDashboardView::RUN_STATE_PAUSED}) swaps its chip for the
     * design-doc S1 stage label ("Sent — waiting for a website") so the parked
     * state reads as deliberately sent, not merely paused — the run kept its
     * CID and is waiting on a destination, not on the worker. The dedicated
     * awaiting copy only wins while that flag is actually set; an ordinary
     * paused run keeps the plain chip.
     *
     * @param 'idle'|'queued'|'running'|'paused'|'completed'|'completed_with_warnings'|'failed'|'cancelled' $runState
     */
    private static function stateLabelFor(string $runState, bool $awaitingWebsite = false): string
    {
        if ($awaitingWebsite && $runState === self::RUN_STATE_PAUSED) {
            return 'Sent — waiting for a website';
        }

        return match ($runState) {
            self::RUN_STATE_IDLE => 'Ready',
            self::RUN_STATE_QUEUED => 'Queued — starting shortly',
            self::RUN_STATE_RUNNING => 'Publishing',
            self::RUN_STATE_PAUSED => 'Paused',
            self::RUN_STATE_COMPLETED => 'Published',
            self::RUN_STATE_COMPLETED_WITH_WARNINGS => 'Published with warnings',
            self::RUN_STATE_FAILED => 'Failed — retry available',
            self::RUN_STATE_CANCELLED => 'Cancelled — retry available',
        };
    }

    private static function stageLabelFor(RunStage $stage): ?string
    {
        return match ($stage) {
            RunStage::Exporting => 'Exporting content',
            RunStage::Uploading => 'Uploading to Pinner',
            RunStage::Publishing => 'Publishing',
            RunStage::Idle, RunStage::Finished => null,
        };
    }

    /**
     * @param 'idle'|'queued'|'running'|'paused'|'completed'|'completed_with_warnings'|'failed'|'cancelled' $runState
     */
    private static function runLabelFor(string $runState, ?string $stageLabel): string
    {
        return match ($runState) {
            self::RUN_STATE_IDLE => 'No publish yet',
            self::RUN_STATE_QUEUED => 'Queued to publish',
            self::RUN_STATE_RUNNING => $stageLabel ?? 'Publishing…',
            self::RUN_STATE_PAUSED => 'Paused',
            self::RUN_STATE_COMPLETED => 'Published',
            self::RUN_STATE_COMPLETED_WITH_WARNINGS => 'Published with warnings',
            self::RUN_STATE_FAILED => 'Publish failed',
            self::RUN_STATE_CANCELLED => 'Cancelled',
        };
    }

    private static function progressPercentFor(RunStage $stage): int
    {
        return match ($stage) {
            RunStage::Exporting => 25,
            RunStage::Uploading => 55,
            RunStage::Publishing => 85,
            RunStage::Finished => 100,
            RunStage::Idle => 0,
        };
    }

    /**
     * The tick delivery cadence the queued ETA derives from — the constant in
     * one place so the server label, the localised client countdown and the
     * expected-start anchor can never disagree about "when it starts".
     */
    private static function cadenceSeconds(): int
    {
        return TickConfig::DEFAULT_TICK_INTERVAL_SECONDS;
    }

    /**
     * Whether a run-state is terminal for the purpose of the progress line.
     *
     * @param 'idle'|'queued'|'running'|'paused'|'completed'|'completed_with_warnings'|'failed'|'cancelled' $runState
     */
    private static function isTerminalCopyState(string $runState): bool
    {
        return in_array($runState, [
            self::RUN_STATE_COMPLETED,
            self::RUN_STATE_COMPLETED_WITH_WARNINGS,
            self::RUN_STATE_FAILED,
            self::RUN_STATE_CANCELLED,
        ], true);
    }

    /**
     * The secondary progress line's end-user copy — per fine stage, backed by
     * the honest denominator (discover-enqueued URLs plus every asset rewrite
     * collected) instead of the misleading "25% / 0 items processed" a
     * cumulative counter implies.
     *
     * The numerator is the stage's TERMINAL summary (persisted at the stage's
     * fixed point, preferred whenever it exists) or, while the stage is still
     * running, its LIVE count read from the shared item table — never the
     * coarse progress_count, a cumulative counter that counts a URL once per
     * pipeline pass (discover insert + capture claim + rewrite pass) and would
     * overstate reality. Because the denominator already counts every asset
     * rewrite collected, a numerator that also reflects those rows can never
     * exceed it: a row must be collected before it can be rewritten or passed
     * through, and the collected count is itself the tally of those rows. Any
     * numeric capture_done/rewrite_done — including a live 0, which is honest
     * and moving — renders the x-of-M line; only their absence (unwired live
     * adapter) falls back to the stage verb.
     *
     * Decision priority (highest first):
     *  - queued → the honest ETA/waiting line (never "0 items processed")
     *  - idle / terminal → the legacy processed-count line, unchanged
     *  - publish-only re-run (publish cursor, no discover total) → republish
     *  - probe/setup/not-started-but-running → "Preparing to export…"
     *  - discover, or any export stage with no total yet → "Discovering…"
     *  - capture / rewrite → the stage verb, or its terminal x-of-M summary
     *  - pack/wrapup → building the export
     *  - coarse Uploading / Publishing tail → upload/publish copy
     *  - awaiting a website → the parked waiting line (never a percentage or
     *    an item count the paused run cannot back with real work)
     *
     * @param 'idle'|'queued'|'running'|'paused'|'completed'|'completed_with_warnings'|'failed'|'cancelled' $runState
     */
    private static function progressCountLabelFor(
        string $runState,
        int $count,
        ?int $queuedAt,
        ?string $pipelineStage,
        ?int $total,
        ?int $captureDone,
        ?int $rewriteDone,
        RunStage $runStage,
        bool $awaitingWebsite = false,
    ): string {
        // A parked run awaiting a website has no progress to show: the upload
        // was sent, the run is paused waiting for a destination, and any
        // percentage or item count would be a fabrication — the honest line
        // names the wait instead (the template also drops the bar/value while
        // awaiting, so the copy never sits next to a made-up number).
        if ($awaitingWebsite) {
            return 'Waiting for a website — the run is paused.';
        }

        // A queued (not-started) run has processed nothing by definition: the
        // honest line leads with when it will begin ("Starting within N
        // seconds — waiting to begin", N = the tick delivery cadence) — never
        // the misleading "0 items processed" an editor reads as "it got zero
        // done". Without a queue-entry timestamp the plain "Waiting to begin"
        // stands alone; the live elapsed/countdown context is added by the
        // client once it has a wall clock. Unchanged.
        if ($runState === self::RUN_STATE_QUEUED) {
            return $queuedAt === null
                ? 'Waiting to begin'
                : self::queuedEtaLabelFor($runState, $queuedAt) . ' — waiting to begin';
        }

        // Idle (no run) and finished states keep the legacy processed-count
        // line; the design deliberately does not repurpose the terminal
        // surface here.
        if ($runState === self::RUN_STATE_IDLE || self::isTerminalCopyState($runState)) {
            return $count . ' items processed';
        }

        // A publish-only re-run (resume 'publish|') owns an intact artifact and
        // no discover total — it never re-exported, so there is nothing to
        // count. The copy says so, rather than pretending discovery is happening.
        if ($pipelineStage === 'publish' && $total === null) {
            return 'Republishing existing build…';
        }

        // Before discovery drains there is no denominator. Name the fine stage
        // honestly: probe/setup (and a freshly started run whose first tick
        // has not pinned a cursor) is "preparing"; once discovery is feeding
        // the queue — or any export stage finds itself without a total yet —
        // it is "discovering".
        if ($pipelineStage === 'probe' || $pipelineStage === 'setup' || $pipelineStage === null) {
            return 'Preparing to export…';
        }

        if ($pipelineStage === 'discover' || $total === null) {
            return 'Discovering content…';
        }

        if ($pipelineStage === 'capture') {
            // Any capture_done (a live per-tick read while the stage runs, or
            // the terminal summary once it drains — including an honest 0)
            // renders the moving x-of-M line; only its absence (unwired live
            // adapter) shows the stage verb.
            return $captureDone === null
                ? 'Capturing content… (' . $total . ' URLs)'
                : 'Captured ' . $captureDone . ' of ' . $total . ' URLs';
        }

        if ($pipelineStage === 'rewrite') {
            // Same deal: rewrite_done is the live Rewritten count in flight
            // and the terminal summary (rewritten + passed-through) once it
            // drains; the verb only when neither exists.
            return $rewriteDone === null
                ? 'Rewriting content… (' . $total . ' URLs)'
                : 'Rewrote ' . $rewriteDone . ' of ' . $total;
        }

        if ($pipelineStage === 'pack' || $pipelineStage === 'wrapup') {
            return 'Building the export (' . $total . ' URLs)';
        }

        // The coarse tail once the fine table has no more specific entry:
        // the publish boundary of a full run (total present) or an upload/
        // publish fallback for an unknown but advanced fine stage.
        return match ($runStage) {
            RunStage::Uploading => 'Uploading… (' . $total . ' URLs)',
            RunStage::Publishing => 'Publishing…',
            default => $count . ' items processed',
        };
    }

    /**
     * The honest queued ETA line the server renders and the client refines
     * into a live countdown: 'Starting within 30 seconds' (the tick delivery
     * cadence from {@see self::cadenceSeconds()}). Null for every non-queued
     * state and for a queued run without a queue-entry timestamp.
     *
     * @param 'idle'|'queued'|'running'|'paused'|'completed'|'completed_with_warnings'|'failed'|'cancelled' $runState
     */
    private static function queuedEtaLabelFor(string $runState, ?int $queuedAt): ?string
    {
        if ($runState !== self::RUN_STATE_QUEUED || $queuedAt === null) {
            return null;
        }

        return 'Starting within ' . self::cadenceSeconds() . ' seconds';
    }

    /**
     * The expected start instant for a queued run (unix seconds): queuedAt
     * plus the tick delivery cadence. This is the single anchor the server
     * renders and the client's countdown reconciles against — the client
     * refreshes it every second until the window passes, then hands the line
     * back to the honest elapsed "waiting" copy.
     *
     * @param 'idle'|'queued'|'running'|'paused'|'completed'|'completed_with_warnings'|'failed'|'cancelled' $runState
     */
    private static function queuedEtaStartAtFor(string $runState, ?int $queuedAt): ?int
    {
        if ($runState !== self::RUN_STATE_QUEUED || $queuedAt === null) {
            return null;
        }

        return $queuedAt + self::cadenceSeconds();
    }

    /**
     * Whether the queued run may be kicked immediately by the editor — the
     * recovery/start-now escape offered once it has honestly been waiting a
     * while (the client reveals it after its wait threshold; this mapping
     * only pins the state eligibility, which is a pure status decision).
     *
     * A queued run is a waiting NotStarted run; a superseded one must never
     * be kicked. The escape funnels through the same routes as the primary
     * publish action — {@see ContentPublishScheduler::startNow()} absorbs a
     * queued run and ticks it immediately — so it requires the same ready
     * surface (ready = complete environment identity, terminal onboarding and
     * eligible content), which both routes hard-require.
     */
    private static function canStartNowEscape(PublishStatus $status, string $runState): bool
    {
        if ($runState !== self::RUN_STATE_QUEUED || $status->superseded) {
            return false;
        }

        return self::readinessFor($status)[0] === self::READINESS_READY;
    }

    /**
     * The REST publish action the queued-run escape fires, or null when the
     * queued run cannot be kicked locally. Mirrors the primary action split:
     * 'start' (first publish) while no identity exists, 'now' (re-publish)
     * once it does — both hit the manual-run-wins path that absorbs the
     * queued run, marks it explicit and schedules an immediate tick.
     *
     * @param 'idle'|'queued'|'running'|'paused'|'completed'|'completed_with_warnings'|'failed'|'cancelled' $runState
     */
    private static function escapeActionFor(PublishStatus $status, string $runState): ?string
    {
        if (!self::canStartNowEscape($status, $runState)) {
            return null;
        }

        return $status->identity === null ? 'start' : 'now';
    }

    /**
     * Whether a first publish may start: readiness ready, no run in flight and
     * no website identity yet. A terminal record — a cancelled or failed first
     * publish — is NOT a blocker: {@see ContentPublishScheduler::startNow()}
     * restarts over a terminal slot (it deletes the terminal record and seeds a
     * fresh pending dirty run), so the retry stays one click away. Only a live
     * (queued/running/paused) run, an unavailable environment, an existing
     * identity or a merely-anomalous completed-without-identity record makes
     * the first-publish action unavailable.
     */
    private static function canStart(PublishStatus $status, string $runState, string $readiness): bool
    {
        if ($status->identity !== null) {
            return false;
        }

        if ($readiness !== self::READINESS_READY || $status->runActive) {
            return false;
        }

        // A first publish can always start: never-ran (idle), or a terminal
        // failed/cancelled record that startNow() replaces with a fresh
        // pending run so the same single-option storage has room to restart.
        return $runState === self::RUN_STATE_IDLE
            || in_array($runState, [self::RUN_STATE_FAILED, self::RUN_STATE_CANCELLED], true);
    }

    private static function canPublishNow(PublishStatus $status, string $runState, string $readiness): bool
    {
        return $status->identity !== null
            && $readiness === self::READINESS_READY
            && !in_array($runState, [self::RUN_STATE_QUEUED, self::RUN_STATE_RUNNING, self::RUN_STATE_PAUSED], true);
    }

    private static function canPublishArtifact(PublishStatus $status, string $runState, string $readiness): bool
    {
        return self::canPublishNow($status, $runState, $readiness)
            && $status->runStatus->isTerminal();
    }

    /**
     * The one prominent publish action the workflow leads with.
     *
     * 'start' for a first publish (no identity yet), 'now' for any re-publish,
     * and null the moment the surface is not ready or a run is already live —
     * at which point the page's primary button renders disabled.
     *
     * @param 'idle'|'queued'|'running'|'paused'|'completed'|'completed_with_warnings'|'failed'|'cancelled' $runState
     */
    private static function primaryActionFor(PublishStatus $status, string $runState, string $readiness): ?string
    {
        if (in_array($runState, [self::RUN_STATE_QUEUED, self::RUN_STATE_RUNNING, self::RUN_STATE_PAUSED], true)) {
            return null;
        }

        if (self::canStart($status, $runState, $readiness)) {
            return 'start';
        }

        if (self::canPublishNow($status, $runState, $readiness)) {
            return 'now';
        }

        return null;
    }

    /**
     * Whether publishing is genuinely warranted at this moment.
     *
     * True only when the surface is ready AND there is something to push
     * (unpublished changes, or a never-published-but-eligible site). This is
     * the same decision the "publish to Pinner" admin notice uses, so the two
     * surfaces can never disagree about whether publishing is worthwhile.
     *
     * @param 'idle'|'queued'|'running'|'paused'|'completed'|'completed_with_warnings'|'failed'|'cancelled' $runState
     */
    private static function needsPublishFor(PublishStatus $status, string $runState, string $readiness): bool
    {
        if ($status->runActive) {
            return false;
        }

        if ($readiness !== self::READINESS_READY) {
            return false;
        }

        return (bool) ($status->dirty || $status->identity === null);
    }

    /**
     * The "why" copy under the primary action: what changed, why publishing is
     * needed, or why it isn't available. Mirrored client-side by cast-publish.js
     * so a no-refresh status refresh never drifts from this server-read copy.
     *
     * A run awaiting a website leads with the design-doc parked copy (the
     * manual path's line) the moment the wait is real — the upload was sent
     * and the run waits for a website, so the runActive "Publishing is
     * paused." line would under-sell it.
     *
     * @param 'idle'|'queued'|'running'|'paused'|'completed'|'completed_with_warnings'|'failed'|'cancelled' $runState
     */
    private static function contextMessageFor(PublishStatus $status, string $runState, string $readiness, bool $awaitingWebsite = false): string
    {
        if ($awaitingWebsite) {
            return 'Your upload was sent. The run is waiting for a website — open Pinner and create one or attach one to this workspace, then come back.';
        }

        if ($status->runActive) {
            return match ($runState) {
                // Plain-language queued copy: what is happening, what happens
                // next, and that the page tracks it — no worker jargon.
                self::RUN_STATE_QUEUED => 'Your publish is queued and will start automatically. Keep working — it runs in the background and this page tracks the progress.',
                self::RUN_STATE_PAUSED => 'Publishing is paused.',
                default => 'Publishing is in progress — progress updates live below.',
            };
        }

        if ($readiness !== self::READINESS_READY) {
            return match ($readiness) {
                self::READINESS_CONFIG => 'Publishing is unavailable until the configuration below is resolved.',
                self::READINESS_SETUP => 'Finish onboarding to publish your site.',
                default => 'Add publishable content, then publish your site to Pinner.',
            };
        }

        if ($runState === self::RUN_STATE_FAILED || $runState === self::RUN_STATE_CANCELLED) {
            // Terminal retry states: a finished publish that did not land. The
            // copy names what happened so the retry is honest, never dressed up
            // as a first-time publish.
            if ($status->identity === null) {
                return $runState === self::RUN_STATE_FAILED
                    ? 'Your last publish failed. Publish to Pinner to retry.'
                    : 'Your last publish was cancelled. Publish to Pinner to start again.';
            }

            return $runState === self::RUN_STATE_FAILED
                ? 'Your last publish failed. Publish again to retry.'
                : 'Your last publish was cancelled. Publish again to start over.';
        }

        if ($status->identity === null) {
            return $status->dirty
                ? 'Your workspace has content ready — publish it to Pinner for the first time.'
                : 'Your site is ready — publish it to Pinner to make it live.';
        }

        return $status->dirty
            ? 'You have unpublished changes ready to go live.'
            : 'Your published site is up to date.';
    }
}
