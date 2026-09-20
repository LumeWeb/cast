<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Environment\EnvIdentity;
use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\PipelineStageKey;
use LumeWeb\Cast\Export\PublishBoundaryStatus;
use LumeWeb\Cast\Export\RunRepository;
use LumeWeb\Cast\Export\RunStage;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Export\WorkItemStateProvider;
use LumeWeb\Cast\Export\WorkItemStatus;
use LumeWeb\Cast\Http\HttpException;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use LumeWeb\Cast\Ipfs\IpnsResolver;
use LumeWeb\Cast\Ipfs\WebsiteRegistry;
use LumeWeb\Cast\Ipfs\WorkspaceLinker;
use LumeWeb\Cast\Jobs\Clock;
use LumeWeb\Cast\Jobs\ContentPublishScheduler;
use LumeWeb\Cast\Jobs\IdentityGateway;
use LumeWeb\Cast\Jobs\PublishIdentity;
use LumeWeb\Cast\Jobs\PublishMode;
use LumeWeb\Cast\Jobs\PublishModeStore;
use LumeWeb\Cast\Onboarding\WizardState;
use LumeWeb\Cast\Onboarding\WizardStore;
use LumeWeb\Cast\Publish\CreateWebsiteRequest;
use LumeWeb\Cast\Publish\PublishRegistry;

/**
 * Admin publish setup/readiness service backing the publish UX module.
 *
 * {@see status()} is a report consumed by the admin dashboard card — it never
 * starts a run or schedules a tick — with two deliberately scoped exceptions:
 * inside the narrow awaiting-website check (a resumable run with no local
 * website id) it consults the account website registry and writes a matched
 * website id through to the persisted identity (see {@see awaitingWebsiteFor()});
 * and when the persisted registry holds a structurally complete pair whose
 * website name is empty (a guided auto-generate whose create response carried
 * no domain), it resolves the real domain by id from the account registry and
 * writes it through (see {@see hydratedIdentity()}), so a hard refresh always
 * shows the connected website. Both stays are cheap — only those narrow checks
 * ever read the network, so an everyday poll stays on the happy path. It also
 * exposes the
 * selected publish mode and whether it auto-publishes. The per-stage
 * numerators (capture_done / rewrite_done) come from the persisted stage
 * summaries once their stage drains, and — through the optional
 * {@see WorkItemStateProvider} — from LIVE reads of the shared item table
 * while capture/rewrite are actually in flight, so the publish card's
 * "Captured X of M URLs" advances every tick instead of freezing on the stage
 * verb. Without the provider the report degrades to the terminal-only copy,
 * unchanged.
 *
 * The denominator (progress_total) is the run's FULL work, not just the
 * discover total: discover-enqueued URLs plus every asset rewrite collected
 * (the cumulative count carried by the rewrite cursor, and pinned on the
 * rewrite summary once the stage drains — see {@see progressTotalFor()}), so
 * the "x of M" copy can never show x exceeding M when reconciliation adds
 * beyond the discover-only list.
 *
 * {@see startFirstPublish()} is the only explicit first-publish starter. It
 * succeeds only when every precondition holds — complete environment identity,
 * terminal onboarding state (Completed or Skipped), eligible public content,
 * no actively executing run, and no existing website/IPNS identity conflict —
 * then queues exactly one auto-tick through the shared
 * {@see ContentPublishScheduler} manual-run-wins primitive (a pending dirty
 * NotStarted run is absorbed rather than refused). It never creates a website
 * or mutates the identity; that happens later in the export/upload stage after
 * a CID is produced, so a website can never be created before a CID exists.
 *
 * {@see startPublishNow()} is the re-publish "publish now" starter: it applies
 * the same manual-run-wins semantics for any later publish while skipping the
 * first-publish-only identity/eligibility checks.
 *
 * {@see cancelRun()} is the explicit cancel: any live (queued, executing,
 * paused or superseded-but-still-live) run is driven to Cancelled and its
 * pending AUTO_HOOK/FOLLOW_UP events are cleared — absent and terminal states
 * are refused side-effect free.
 *
 * {@see publishExisting()} is the retry/resume path: an intact packed artifact
 * owned by a terminal (or parked, Paused) run is reused by a fresh
 * publish-only run (resume 'publish|') that jumps straight to the publish
 * boundary and schedules one immediate tick, without re-exporting.
 *
 * {@see createWebsite()} and {@see linkWebsite()} are the guided website-card
 * actions for a parked first publish: they only ever run inside the narrow
 * awaiting-website check and, after the portal accepts the create/attach,
 * record the persisted website binding (the same option the status and
 * publishExisting already read) so the run un-parks on the next status/tick —
 * the operator then resumes through {@see publishExisting()}, reusing the
 * preserved CID without re-uploading the pack. {@see availableWebsites()}
 * feeds the link picker from the same account registry, excluding the
 * workspace's own attached website.
 */
final class PublishSetupService
{
    public function __construct(
        private readonly EnvIdentity $env,
        private readonly WizardStore $wizardStore,
        private readonly PublishedContentProbe $content,
        private readonly RunRepository $repository,
        private readonly IdentityGateway $identity,
        private readonly ContentPublishScheduler $contentScheduler,
        private readonly PublishModeStore $modeStore,
        private readonly Clock $clock,
        private readonly ?ConnectionResolver $connection = null,
        private readonly ?NoticeDismissalStore $noticeDismissals = null,
        // The live per-stage count adapter: the same WorkItemStateProvider the
        // pre-pack validation check reads (countByStatus over the shared item
        // table). Optional so the service's read path never depends on one; a
        // status built without it keeps the terminal-only numerators.
        private readonly ?WorkItemStateProvider $workItems = null,
        // The awaiting-website registry-first adapters: the account website list
        // (GET /api/websites, plus the explicit create the guided card needs)
        // consulted ONLY when a run is resumable with no local website id, the
        // PublishRegistry for the cache write-through once a website is found/
        // created/linked, the workspace linker (POST /api/workspaces/{id}/
        // attach) for the guided link path, and the IPNS resolver that turns an
        // IPNS-targeted website's target_hash (an IPNS name) back into the
        // immutable CID the preserved CID is compared against. All optional:
        // without them the derivation never reads the network and degrades to
        // the run's own park signal, and the guided actions refuse with {@see
        // PublishWebsiteRefusal::Unavailable}.
        private readonly ?WebsiteRegistry $websites = null,
        private readonly ?PublishRegistry $registry = null,
        private readonly ?WorkspaceLinker $workspaces = null,
        private readonly ?IpnsResolver $ipns = null,
    ) {
    }

    public function status(): PublishStatus
    {
        $envProblems = $this->env->problems();
        $wizardState = $this->wizardStore->load()->state;
        $latest = $this->repository->latest();
        $mode = $this->modeStore->mode();
        $connection = $this->connection?->current();
        // The identity first — with the narrow empty-name backfill (see
        // {@see hydratedIdentity()}) — so the display below always carries a
        // real website name on a refresh, and the awaiting derivation below
        // sees the settled identity instead of re-consulting the account.
        $identity = $this->hydratedIdentity();
        // The registry-first wait signal second, so the preserved CID is only
        // surfaced when the wait is real (and after any write-through has had
        // its say) — never alongside a settled identity.
        $awaitingWebsite = $this->awaitingWebsiteFor($latest);

        return new PublishStatus(
            bootstrapIdentityComplete: $envProblems === [],
            envProblems: $envProblems,
            onboardingComplete: $this->isTerminalOnboarding($wizardState),
            hasEligibleContent: $this->content->hasEligibleContent(),
            mode: $mode,
            autoActive: $mode->isAutomatic(),
            runStatus: $latest === null ? RunStatus::NotStarted : $latest->status,
            runStage: $latest === null ? RunStage::Idle : $latest->stage,
            progressCount: $latest === null ? 0 : $latest->progressCount,
            // The fine pipeline stage from the resume cursor prefix. When the
            // cursor is empty (a freshly started run before its first tick) or
            // unparseable it stays null, which the UI reads as "preparing".
            pipelineStage: $latest === null ? null : self::pipelineStageFor($latest),
            // The honest full-run denominator: the discover-enqueued URL total
            // plus every asset rewrite collected (the same cumulative count the
            // rewrite cursor carries while the stage runs). Null before
            // discovery drains and on publish-only re-runs, where the UI shows
            // indeterminate copy.
            progressTotal: self::progressTotalFor($latest),
            captureDone: $this->captureDoneFor($latest),
            rewriteDone: $this->rewriteDoneFor($latest),
            // The queue-entry time only exists while a run is actually waiting
            // (NotStarted). Once it starts or finishes there is nothing to
            // time, so the client falls back to the item count + stage.
            queuedAt: $latest !== null && $latest->status === RunStatus::NotStarted ? $latest->createdAt : null,
            runActive: $latest !== null && !$latest->isTerminal(),
            dirty: $latest !== null && $latest->dirty,
            superseded: $latest !== null && $latest->superseded,
            publishCid: $latest?->publishCid,
            lastError: $latest?->lastError,
            identity: $identity,
            // The Connection card state is resolved lazily through the optional
            // resolver — never during boot/activation. Without a resolver the
            // connection stays null and nothing about the portal is fetched.
            connection: $connection === null ? null : ConnectionView::fromSelfIdentification($connection),
            // The registry-first "sent — waiting for a website" signal: only a
            // resumable run with no local website id ever consults the account
            // registry, so a plain dashboard poll never reads the network.
            awaitingWebsite: $awaitingWebsite,
            // The preserved CID of the park, surfaced only while actually
            // awaiting (the top-level publish_cid is null for a resumable
            // boundary, so the S1 copy quotes this instead).
            awaitingCid: $awaitingWebsite ? self::parkedCidFor($latest) : null,
        );
    }

    public function startFirstPublish(): PublishStartResult
    {
        if (!$this->env->isComplete()) {
            return new PublishStartResult(false, PublishStartRefusal::EnvIdentityMissing);
        }

        if (!$this->isTerminalOnboarding($this->wizardStore->load()->state)) {
            return new PublishStartResult(false, PublishStartRefusal::OnboardingNotComplete);
        }

        if (!$this->content->hasEligibleContent()) {
            return new PublishStartResult(false, PublishStartRefusal::NoEligibleContent);
        }

        // An actively executing run (or a superseded one) must not be stacked.
        // A queued pending (NotStarted, dirty) run is absorbable: it is the
        // first publish's work, so the shared primitive ticks it immediately.
        $latest = $this->repository->latest();
        if ($latest !== null && !$latest->isTerminal() && $latest->status !== RunStatus::NotStarted) {
            return new PublishStartResult(false, PublishStartRefusal::RunActive);
        }

        if ($this->identity->hasIdentity()) {
            return new PublishStartResult(false, PublishStartRefusal::IdentityConflict);
        }

        // Manual-run-wins: absorb the pending run, or create a fresh dirty run
        // when none is live — never a second stacked run. Side-effect-free on
        // refusal (an actively running/superseded run maps to RunActive).
        $started = $this->contentScheduler->startNow();
        if (!$started->started) {
            return new PublishStartResult(false, PublishStartRefusal::RunActive);
        }

        // A fresh publish is the user acting on the "publish to Pinner" prompt,
        // so the notice's dismissal is re-armed for the next content change.
        $this->clearPublishNoticeDismissal();

        return new PublishStartResult(true, null, $started->runId);
    }

    /**
     * Explicit "publish now" for a site that already published once.
     *
     * Hard preconditions only: a complete environment identity and a terminal
     * onboarding state. Unlike {@see startFirstPublish()} it does not require
     * eligible content or an absent identity — the operator is pushing the
     * current state out through the dashboard/admin-bar button. Queueing is
     * delegated to the shared manual-run-wins {@see ContentPublishScheduler}
     * primitive: a pending auto-run is absorbed, and only an actively
     * executing or superseded run is refused (as RunActive).
     */
    public function startPublishNow(): PublishStartResult
    {
        if (!$this->env->isComplete()) {
            return new PublishStartResult(false, PublishStartRefusal::EnvIdentityMissing);
        }

        if (!$this->isTerminalOnboarding($this->wizardStore->load()->state)) {
            return new PublishStartResult(false, PublishStartRefusal::OnboardingNotComplete);
        }

        $started = $this->contentScheduler->startNow();
        if (!$started->started) {
            return new PublishStartResult(false, PublishStartRefusal::RunActive);
        }

        // Same notice re-arm as the first publish: the operator is pushing the
        // current state out, so a later dismissal is not inherited by the next
        // round of changes.
        $this->clearPublishNoticeDismissal();

        return new PublishStartResult(true, null, $started->runId);
    }

    /**
     * Cancel the live run and stop its pending publish events.
     *
     * Any non-terminal run — queued (NotStarted), executing (Running), paused,
     * or superseded-but-still-live — is driven to Cancelled, and every pending
     * AUTO_HOOK/FOLLOW_UP publish event for it is cleared. Absent (NoRun) and
     * terminal (RunTerminal) states are refused and are strictly side-effect
     * free: the finished record is left untouched and queued events are not
     * cleared. The run transition graph enforces the superseded rule — an
     * overtaken run can only reach Cancelled through this path.
     */
    public function cancelRun(): PublishCancelResult
    {
        $latest = $this->repository->latest();

        if ($latest === null) {
            return new PublishCancelResult(false, PublishCancelRefusal::NoRun);
        }

        if ($latest->isTerminal()) {
            return new PublishCancelResult(false, PublishCancelRefusal::RunTerminal);
        }

        // A live run may always be cancelled (the graph routes superseded runs
        // here and Running/Paused/NotStarted towards the same terminal state).
        $latest->cancel(at: $this->clock->now());
        $this->repository->save($latest);

        // Stop the debounce and any coalesced follow-up owned by this run.
        $this->contentScheduler->clearPending();

        return new PublishCancelResult(true, null, $latest->runId);
    }

    /**
     * Publish an intact existing artifact without re-exporting.
     *
     * Requires a complete environment identity, a terminal onboarding state,
     * and a source run that owns an intact pack (a non-empty ZIP path). The
     * source is a terminal run that already published once — which also needs
     * the existing website/IPNS identity to re-point — or a parked first
     * publish (Paused with no website yet), whose replay needs no stored
     * identity because nothing was ever recorded against a website. Either way
     * the source slot is replaced by one fresh publish-only run (resumeCursor
     * 'publish|') that reuses the packed artifact and the source settings
     * snapshot, carries any previously recorded publish identifiers, and
     * schedules exactly one immediate tick. Every refusal is side-effect free —
     * nothing is seeded or scheduled.
     */
    public function publishExisting(): PublishExistingResult
    {
        if (!$this->env->isComplete()) {
            return new PublishExistingResult(false, PublishExistingRefusal::EnvIdentityMissing);
        }

        if (!$this->isTerminalOnboarding($this->wizardStore->load()->state)) {
            return new PublishExistingResult(false, PublishExistingRefusal::OnboardingNotComplete);
        }

        $source = $this->repository->latest();
        if ($source === null) {
            return new PublishExistingResult(false, PublishExistingRefusal::NoArtifact);
        }

        // A parked first publish (Paused, no website attached yet) is resumable
        // even though it never reached a terminal state: nothing was ever
        // recorded against a website, so the intact pack and the publish-only
        // cursor carry everything the replay needs. Any other live run (queued,
        // running or superseded) is still refused — the single-run-slot
        // invariant stands.
        if (!$source->isTerminal() && $source->status !== RunStatus::Paused) {
            return new PublishExistingResult(false, PublishExistingRefusal::RunActive);
        }

        if (!$this->hasIntactArtifact($source)) {
            return new PublishExistingResult(false, PublishExistingRefusal::NoArtifact);
        }

        // Re-publishing a terminal run needs the existing website/IPNS identity
        // to re-point. A parked first publish has none by construction (identity
        // is only recorded once a website exists), so the resume skips this
        // check and the replay completes the identity from its own publish
        // result.
        if ($source->isTerminal() && !$this->identity->hasIdentity()) {
            return new PublishExistingResult(false, PublishExistingRefusal::IdentityMissing);
        }

        $seeded = $this->contentScheduler->publishExisting($source);
        if (!$seeded->started || $seeded->runId === null) {
            return new PublishExistingResult(false, PublishExistingRefusal::RunActive);
        }

        // The artifact republish is as much of a fresh publish as now/start:
        // re-arm the prompt notice for the content changes after this one.
        $this->clearPublishNoticeDismissal();

        return new PublishExistingResult(true, null, $seeded->runId);
    }

    /**
     * The currently persisted publish trigger mode.
     */
    public function mode(): PublishMode
    {
        return $this->modeStore->mode();
    }

    /**
     * Persist a new publish trigger mode.
     *
     * Only a known {@see PublishMode} value is accepted; an unknown value is
     * refused (side-effect free) so a corrupt/forged request can never flip a
     * site into or out of auto-publishing by accident.
     */
    public function setMode(string $mode): PublishModeResult
    {
        $parsed = PublishMode::tryFrom($mode);
        if ($parsed === null) {
            return new PublishModeResult(false, $this->modeStore->mode(), PublishModeRefusal::InvalidMode);
        }

        $this->modeStore->setMode($parsed);

        return new PublishModeResult(true, $parsed);
    }

    /**
     * Whether the current user has dismissed the "publish to Pinner" notice.
     *
     * Defaults to not-dismissed (false) when no per-user store is composed.
     */
    public function isPublishNoticeDismissed(): bool
    {
        return $this->noticeDismissals?->currentUserDismissed() ?? false;
    }

    /**
     * Persist that the current user dismissed the "publish to Pinner" notice.
     */
    public function dismissPublishNotice(): void
    {
        $this->noticeDismissals?->dismiss();
    }

    /**
     * Re-arm the notice prompt by clearing the current user's dismissal.
     *
     * Called by every publish-initiating action ({@see startFirstPublish()},
     * {@see startPublishNow()}, {@see publishExisting()}) so a dismissal only
     * ever suppresses the prompt for the current round of changes, never for
     * the content the user publishes later.
     */
    public function clearPublishNoticeDismissal(): void
    {
        $this->noticeDismissals?->clear();
    }

    /**
     * Whether the terminal run owns an intact, reusable packed artifact.
     *
     * A pack record only counts once it names a real ZIP path; a failed/empty
     * pack (e.g. an empty zip path) is not a retryable source.
     */
    private function hasIntactArtifact(ExportRun $run): bool
    {
        return $run->pack !== null && $run->pack->zipPath !== '';
    }

    /**
     * The fine pipeline boundary encoded by the run's resume cursor prefix.
     *
     * Only the eight known {@see PipelineStageKey} prefixes are surfaced; an
     * empty cursor (a freshly started run before its first tick) or an
     * unparseable token yields null so the UI treats it as "preparing".
     */
    private static function pipelineStageFor(?ExportRun $run): ?string
    {
        if ($run === null || $run->resumeCursor === '') {
            return null;
        }

        $prefix = explode('|', $run->resumeCursor, 2)[0];

        return PipelineStageKey::tryFrom($prefix)?->value;
    }

    /**
     * The honest denominator for the whole run's x-of-M copy: every distinct
     * work item this run is responsible for, i.e. the discover-enqueued URL
     * total PLUS every asset rewrite collected. A run only knows a URL total
     * once discovery drains, so it stays null before that and on publish-only
     * re-runs (where the UI shows indeterminate copy instead of a made-up
     * number).
     *
     * The collected half is additive and never shrinks: rewrite is the only
     * stage that collects, and it only runs after capture drains, so during
     * capture the count is 0 and during rewrite/pack+ it is whatever the stage
     * has accumulated. The stage persists that cumulative count as the plain
     * digit payload of the 'rewrite|' resume cursor while it runs; once the
     * rewrite summary lands at the fixed point the same count is pinned on the
     * summary itself ({@see RewriteSummary::$assetsCollected}), so the total
     * never forgets the collected half when the cursor advances to pack. Both
     * sources agree at the boundary and either is null-safe.
     */
    private static function progressTotalFor(?ExportRun $run): ?int
    {
        if ($run === null || $run->discover === null) {
            return null;
        }

        return $run->discover->enqueued + self::collectedAssetsFor($run);
    }

    /**
     * The cumulative count of distinct asset/media rows rewrite has collected
     * this run — the second half of the honest denominator (see
     * {@see progressTotalFor()}).
     *
     * While rewrite is in flight the stage persists this count as the plain
     * digit payload of the 'rewrite|' resume cursor ({@see RewriteStage}); the
     * capture-stage cursor never carries a collected count, so only the
     * rewrite prefix is read. Once the rewrite summary exists (the stage moved
     * past rewrite, so the cursor no longer carries the count) the summary's
     * pinned {@see RewriteSummary::$assetsCollected} takes over — the two can
     * never disagree because the summary is computed from the same cursor at
     * the fixed point, and a reachable persisted state never has a rewrite
     * summary AND a rewrite cursor together (the cursor advances in the same
     * tick the summary lands).
     *
     * The x <= M invariant falls out of this structure: every Rewritten/Done
     * row is either a discover-enqueued row or a collected row, and a row can
     * only be rewritten/passed through after it exists — so the live or
     * terminal numerator can never exceed discover-enqueued + collected, no
     * matter which source is read.
     */
    private static function collectedAssetsFor(?ExportRun $run): int
    {
        if ($run === null) {
            return 0;
        }

        $pieces = explode('|', $run->resumeCursor, 2);
        if (
            ($pieces[0] ?? '') === PipelineStageKey::Rewrite->value
            && isset($pieces[1])
            && $pieces[1] !== ''
            && ctype_digit($pieces[1])
        ) {
            return (int) $pieces[1];
        }

        return $run->rewrite->assetsCollected ?? 0;
    }

    /**
     * The capture numerator, preferring the persisted terminal
     * {@see \LumeWeb\Cast\Export\CaptureSummary} (immutable fixed-point truth)
     * whenever it exists, and falling back to a LIVE read of the shared item
     * table only while capture is actually in flight — so the publish card's
     * "Captured X of M URLs" moves every tick instead of freezing on the stage
     * verb.
     *
     * The live count is every row capture terminally recorded so far: done +
     * failed + skipped. During capture no row can be rewritten (rewrite only
     * starts once capture drains) and the sum converges exactly on the summary
     * that lands at the fixed point, so it is honest at every instant and
     * continuous across the summary boundary (0 is a real, moving value).
     *
     * Null — and the stage-verb fallback — is reserved for when neither the
     * summary nor a live provider exists (or the cursor is elsewhere), never
     * for the in-flight gap itself.
     */
    private function captureDoneFor(?ExportRun $run): ?int
    {
        $capture = $run?->capture;

        if ($capture !== null) {
            return $capture->done + $capture->failed + $capture->skipped;
        }

        if ($this->workItems === null || self::pipelineStageFor($run) !== 'capture') {
            return null;
        }

        return $this->workItems->countByStatus(WorkItemStatus::Done)
            + $this->workItems->countByStatus(WorkItemStatus::Failed)
            + $this->workItems->countByStatus(WorkItemStatus::Skipped);
    }

    /**
     * The rewrite numerator, preferring the persisted terminal
     * {@see \LumeWeb\Cast\Export\RewriteSummary} whenever it exists, and
     * falling back to a LIVE read only while rewrite is in flight.
     *
     * The only per-row mark the rewrite stage leaves as it goes is flipping a
     * completed text row to Rewritten — pass-through rows keep Done precisely
     * so a restart can resume from the unrewritten ones — so the live count is
     * exactly the Rewritten rows: every row the stage has genuinely rewritten
     * so far. Counting Done rows too would claim pass-through credit for text
     * rows rewrite has not touched yet (Done is both "passed through" and
     * "awaiting rewrite", with no status-level way to tell them apart) and
     * would show "Rewrote M of M" the instant rewrite begins. The terminal
     * summary (rewritten + passed-through) takes over at the fixed point; the
     * remaining bound is that honest completion.
     */
    private function rewriteDoneFor(?ExportRun $run): ?int
    {
        $rewrite = $run?->rewrite;

        if ($rewrite !== null) {
            return $rewrite->rewritten + $rewrite->passedThrough;
        }

        if ($this->workItems === null || self::pipelineStageFor($run) !== 'rewrite') {
            return null;
        }

        return $this->workItems->countByStatus(WorkItemStatus::Rewritten);
    }

    private function isTerminalOnboarding(WizardState $state): bool
    {
        return $state === WizardState::Completed || $state === WizardState::Skipped;
    }

    /**
     * Explicitly create a website for the parked first publish (guided path a).
     *
     * Deliberately the ONLY entry-point that may strike the create call, so the
     * "no silent auto-create" contract holds by construction (an ordinary poll
     * and every tick stay read-only). Guards, in order:
     *
     *  1. a run must actually be parked awaiting a website — otherwise the
     *     action refuses side-effect free;
     *  2. the registry adapter must be wired (a complete portal identity) and the
     *     persisted registry must be present for the write-through — otherwise
     *     {@see PublishWebsiteRefusal::Unavailable};
     *  3. the portal create must succeed — a rejection maps to
     *     {@see PublishWebsiteRefusal::CreateFailed} with nothing recorded.
     *
     * Destination intent is explicit, never label-derived. An EMPTY hostname is
     * the auto-generate confirmation: the request carries generate + managed
     * dns hosting and NO domain (and no made-up label — the original
     * "site.pinned.site" came from the empty-hostname 'site' label reaching the
     * wire). A NAMED hostname is a custom domain: domain + namespace 'icann' +
     * managed dns hosting, no generate flag — the pinner CLI's custom-domain
     * contract. The target matches the ipns default: the run's already
     * published IPNS name when the run targets ipns (the park created and
     * published the key first), falling back to the preserved CID + legacy ipfs
     * for an ipfs-mode run — see {@see websiteTargetFor()}.
     *
     * After the portal create succeeds the new website is AUTO-ATTACHED to the
     * workspace (POST /api/workspaces/{id}/attach, the same link the guided
     * link path uses) when the attach adapter is wired, and only then is the
     * website binding recorded via {@see PublishRegistry::recordWebsite()} (the
     * same option status/publishExisting read), so the run un-parks on the
     * next status/tick and the operator resumes through
     * {@see publishExisting()}. Ordering matters: the attach precedes the
     * write-through, so a failed attach can never fake a linked state. An
     * attach 409 is tolerated when the workspace already holds this run's
     * website (verified by the shared registry CID match) and surfaced as a
     * typed {@see PublishWebsiteRefusal::WorkspaceAlreadyLinked} conflict when
     * it holds a different website.
     */
    public function createWebsite(string $hostname = ''): PublishWebsiteCreateResult
    {
        $run = $this->repository->latest();
        if (!$this->awaitingWebsiteFor($run)) {
            return new PublishWebsiteCreateResult(false, PublishWebsiteRefusal::NotAwaitingWebsite);
        }

        if ($this->websites === null || $this->registry === null) {
            return new PublishWebsiteCreateResult(false, PublishWebsiteRefusal::Unavailable);
        }

        $cid = self::parkedCidFor($run);
        if ($cid === null) {
            return new PublishWebsiteCreateResult(false, PublishWebsiteRefusal::NotAwaitingWebsite);
        }

        [$targetHash, $targetType] = $this->websiteTargetFor($run, $cid);

        $domain = $hostname !== '' ? trim($hostname) : null;

        try {
            $website = $this->websites->create(new CreateWebsiteRequest(
                targetHash: $targetHash,
                targetType: $targetType,
                // label is optional metadata: only a real hostname is a sane
                // label, the empty (auto-generate) path omits it entirely so no
                // placeholder can leak into the minted platform subdomain.
                label: $domain,
                domain: $domain,
                namespace: $domain !== null ? 'icann' : null,
                generate: $domain === null,
                dnsHostingEnabled: true,
            ));
        } catch (HttpException) {
            return new PublishWebsiteCreateResult(false, PublishWebsiteRefusal::CreateFailed);
        }

        // Auto-attach the freshly created website to this workspace (the pinner
        // workspaces-attach half of a complete guided create). In the awaiting
        // state the workspace has no website yet, so this attach succeeds. It
        // runs BEFORE the write-through so a failed attach can never fake a
        // linked state.
        //
        // A 409 means the workspace already holds a website. That is tolerated
        // exactly when the held website IS this run's website — its immutable
        // target serves the preserved CID, verified by the SAME registry match
        // the awaiting derivation and link picker use (uniform write-through):
        // an earlier attempt already attached a website for this run, so the
        // create reports success and that already-attached website is what the
        // identity records (no double-attach). A 409 against a DIFFERENT
        // website is a real conflict and surfaces as a typed refusal, never a
        // raw exception.
        if ($this->workspaces !== null && $this->connection !== null) {
            $workspaceId = $this->resolveWorkspaceId();
            if ($workspaceId === null) {
                return new PublishWebsiteCreateResult(false, PublishWebsiteRefusal::NoWorkspace);
            }

            try {
                $this->workspaces->attach($workspaceId, (int) $website->id());
            } catch (UnexpectedStatusCodeException $exception) {
                if ($exception->status() !== 409) {
                    return new PublishWebsiteCreateResult(false, PublishWebsiteRefusal::CreateFailed);
                }
                $already = $this->websiteServingCid($cid);
                if ($already === null) {
                    return new PublishWebsiteCreateResult(false, PublishWebsiteRefusal::WorkspaceAlreadyLinked);
                }
                $website = $already;
            } catch (HttpException) {
                return new PublishWebsiteCreateResult(false, PublishWebsiteRefusal::CreateFailed);
            }
        }

        // Write-through backfill: the create response may not carry the minted
        // platform domain (auto-generate returns an empty domain until the
        // subdomain lands), so when it is empty the name is resolved by id from
        // the account registry (cheap — only this empty branch), which knows
        // the real domain; a still-unknown site degrades to the id so the
        // persisted identity never carries an empty name.
        $name = $website->domain() !== '' ? $website->domain() : $this->websiteNameFor($website->id());
        $this->registry->recordWebsite((string) $website->id(), $name);

        return new PublishWebsiteCreateResult(
            true,
            websiteId: (string) $website->id(),
            websiteName: $name,
            domain: $website->domain(),
            status: $website->status(),
        );
    }

    /**
     * Link an existing account website to this workspace (guided path b).
     *
     * Runs only inside the narrow awaiting-website check. The workspace id is
     * resolved from the lazily-resolved connection (Workspaces.Resolve already
     * reads the portal relationship); the link itself goes through the attach
     * client (POST /api/workspaces/{id}/attach). On success the website
     * binding is recorded (the same write-through createWebsite uses) so the
     * run un-parks on the next status/tick and the operator resumes through
     * {@see publishExisting()}. A portal rejection is surfaced by the card and
     * nothing is recorded on failure: an attach 409 — "already linked to
     * another workspace", which the list payload cannot express — maps to
     * {@see PublishWebsiteRefusal::WebsiteAlreadyLinked} so the card can show
     * friendly actionable copy, while any other rejection stays
     * {@see PublishWebsiteRefusal::LinkFailed}.
     */
    public function linkWebsite(int $websiteId): PublishWebsiteLinkResult
    {
        if ($websiteId < 1) {
            return new PublishWebsiteLinkResult(false, PublishWebsiteRefusal::InvalidWebsiteId);
        }

        $run = $this->repository->latest();
        if (!$this->awaitingWebsiteFor($run)) {
            return new PublishWebsiteLinkResult(false, PublishWebsiteRefusal::NotAwaitingWebsite);
        }

        if ($this->workspaces === null || $this->registry === null) {
            return new PublishWebsiteLinkResult(false, PublishWebsiteRefusal::Unavailable);
        }

        $workspaceId = $this->resolveWorkspaceId();
        if ($workspaceId === null) {
            return new PublishWebsiteLinkResult(false, PublishWebsiteRefusal::NoWorkspace);
        }

        try {
            $this->workspaces->attach($workspaceId, $websiteId);
        } catch (UnexpectedStatusCodeException $exception) {
            // A 409 is the one conflict the picker list cannot express (the
            // payload carries no "linked to a workspace" marker): the chosen
            // website already belongs to a workspace. Translate it into the
            // typed already-linked refusal so the card can surface friendly
            // copy — never a raw exception. Other statuses stay LinkFailed.
            if ($exception->status() === 409) {
                return new PublishWebsiteLinkResult(false, PublishWebsiteRefusal::WebsiteAlreadyLinked);
            }

            return new PublishWebsiteLinkResult(false, PublishWebsiteRefusal::LinkFailed);
        } catch (HttpException) {
            return new PublishWebsiteLinkResult(false, PublishWebsiteRefusal::LinkFailed);
        }

        $this->registry->recordWebsite((string) $websiteId, $this->websiteNameFor($websiteId));

        return new PublishWebsiteLinkResult(true, websiteId: (string) $websiteId);
    }

    /**
     * The account's websites for the link picker (guided path b).
     *
     * Reads the account registry once and serializes JSON-safe rows, excluding
     * the workspace's own already-attached website (the identity's website id
     * and any website whose target_hash is the preserved CID — the registry-
     * first write-through candidate). The list payload carries no "linked to a
     * workspace" marker, so no row is greyed out; an attach conflict surfaces
     * through the link action instead. A read failure maps to
     * {@see PublishWebsiteRefusal::ListFailed}.
     *
     * @return PublishWebsiteListResult the typed outcome; its {@see PublishWebsiteListResult::$websites}
     *                                  rows are the JSON-safe picker rows
     */
    public function availableWebsites(): PublishWebsiteListResult
    {
        if ($this->websites === null) {
            return new PublishWebsiteListResult(false, PublishWebsiteRefusal::Unavailable);
        }

        try {
            $websites = $this->websites->list();
        } catch (HttpException) {
            return new PublishWebsiteListResult(false, PublishWebsiteRefusal::ListFailed);
        }

        $excluded = [];
        $ownedId = $this->identity->current()?->websiteId;
        if ($ownedId !== null && $ownedId !== '') {
            $excluded[$ownedId] = true;
        }
        $cid = self::parkedCidFor($this->repository->latest());
        if ($cid !== null) {
            foreach ($websites as $website) {
                // Compare immutable values: an IPNS-targeted website's
                // target_hash is a mutable IPNS name, so it is resolved to the
                // CID it currently serves before the equality check.
                if ($this->websiteImmutableCidFor($website) === $cid) {
                    $excluded[(string) $website->id()] = true;
                }
            }
        }

        $rows = [];
        foreach ($websites as $website) {
            $id = (string) $website->id();
            if (isset($excluded[$id])) {
                continue;
            }
            $rows[] = [
                'website_id' => $id,
                'domain' => $website->domain(),
                'status' => $website->status(),
                'target_hash' => $website->targetHash(),
                'target_type' => $website->targetType(),
            ];
        }

        return new PublishWebsiteListResult(true, null, $rows);
    }

    /**
     * The workspace id the attach action targets, resolved lazily from the
     * connection (never from a client-supplied value).
     */
    private function resolveWorkspaceId(): ?string
    {
        $self = $this->connection?->current();
        if ($self === null || !$self->isResolved() || $self->workspace() === null) {
            return null;
        }

        $id = $self->workspace()->id();

        return $id > 0 ? (string) $id : null;
    }

    /**
     * A display name for a linked website: the bound domain from the account
     * registry when the row exists, falling back to the website id so the
     * persisted identity never carries an empty name.
     */
    private function websiteNameFor(int $websiteId): string
    {
        if ($this->websites !== null) {
            try {
                foreach ($this->websites->list() as $website) {
                    if ($website->id() === $websiteId) {
                        return $website->domain() !== '' ? $website->domain() : (string) $website->id();
                    }
                }
            } catch (HttpException) {
                // Registry unreadable: the id still names the identity.
            }
        }

        return (string) $websiteId;
    }

    /**
     * The identity the status report surfaces, with a narrow empty-name
     * backfill so a refresh always carries a usable website name.
     *
     * The identity is read from the persisted registry record first. When it
     * is already present (a fully ready pair, or a pair whose website name was
     * recorded) it is returned unchanged — and the account registry is NEVER
     * consulted for it, keeping an ordinary poll off the network.
     *
     * Backfill check: the identity only comes back null here despite the
     * persisted registry holding BOTH a website id and the IPNS key — the one
     * remaining field {@see PublishIdentity::fromOptionValue()} can be
     * rejecting is the website NAME (it refuses empty names). That is exactly
     * the guided auto-generate hole: the create response carried no domain, so
     * the write-through recorded an empty name and the refresh path would
     * otherwise show no website at all. In that case the domain is resolved by
     * id from the account registry (cheap — only this empty branch) and
     * written through via the uniform {@see PublishRegistry::recordWebsite()},
     * so a hard refresh hydrates both id and name even when the link was
     * recorded in an earlier request. A missing half (no website id, or no
     * IPNS key yet) never triggers: the name is not the blocker there, so
     * those ordinary states stay off the network.
     */
    private function hydratedIdentity(): ?PublishIdentity
    {
        $identity = $this->identity->current();
        if ($identity !== null) {
            return $identity;
        }

        $state = $this->registry?->current();
        if ($state === null || $state->websiteId === null || $state->ipnsKey === null || $state->ipnsKeyId === null) {
            return null;
        }

        $this->registry->recordWebsite($state->websiteId, $this->websiteNameFor((int) $state->websiteId));

        return $this->identity->current();
    }

    /**
     * The website-create target for a parked run: [targetHash, internal token].
     *
     * Mirrors PublishService::websiteTargetHash() exactly. With Cast's ipns
     * default the park already created the IPNS key and published the CID to it
     * (the publish flow reconciles the IPNS half before it strikes the website
     * create), so the resumable boundary carries the published name: target
     * 'ipns' with target_hash = that name — the site follows the newest CID
     * through the stable mutable name. A legacy ipfs-mode run (or a run with no
     * published name yet) falls back to the preserved CID + 'ipfs', targeting
     * the immutable artifact directly. Only ipns|ipfs ever reaches the wire
     * (IpfsWebsitesClient::wireTargetType passes both through). A null run (no
     * latest run at all) can never reach the create-time check, so it degrades to the
     * CID fallback.
     *
     * @return array{string, string} [targetHash, internalTargetType]
     */
    private function websiteTargetFor(?ExportRun $run, string $cid): array
    {
        if ($run === null) {
            return [$cid, 'ipfs'];
        }

        if ($run->settings->targetType === 'ipns') {
            $ipnsName = $run->ipnsKey;
            if ($ipnsName === null || $ipnsName === '') {
                $ipnsName = $run->publish?->ipnsKey;
            }
            if ($ipnsName !== null && $ipnsName !== '') {
                return [$ipnsName, 'ipns'];
            }
        }

        return [$cid, 'ipfs'];
    }

    /**
     * The preserved CID of a parked (non-Cancelled) publish boundary, or null
     * when the run holds a local website id or never parked resumable. This is
     * the pure run-level check half of {@see awaitingWebsiteFor()} — the CID
     * surfacing + the guided actions only use it once the waiting signal has
     * already been established.
     */
    private static function parkedCidFor(?ExportRun $run): ?string
    {
        if ($run === null || $run->websiteId !== null) {
            return null;
        }

        $boundary = $run->publish;
        if ($boundary === null || $boundary->status !== PublishBoundaryStatus::Resumable) {
            return null;
        }

        $cid = $boundary->cid;

        return $cid === null || $cid === '' ? null : $cid;
    }

    /**
     * The immutable CID a listed website currently serves, for comparing with
     * the run's preserved CID. An IPFS-targeted website's target_hash IS the
     * CID, so it is returned untouched. An IPNS-targeted website's target_hash
     * is a mutable IPNS name (Cast's ipns default), so it is resolved to the
     * CID the name currently points at; a resolution failure — the name is
     * not published yet — yields null, which callers treat as no-match,
     * exactly like a website that does not serve the preserved CID.
     */
    private function websiteImmutableCidFor(\LumeWeb\Cast\Ipfs\Website $website): ?string
    {
        if ($website->targetType() !== 'ipns' || $this->ipns === null) {
            return $website->targetHash();
        }

        try {
            return $this->ipns->resolve($website->targetHash())->cid();
        } catch (HttpException) {
            // The IPNS name has no resolvable publication yet: it cannot be
            // proven to serve the preserved CID, so it does not match.
            return null;
        }
    }

    /**
     * The registry-first "sent — waiting for a website" derivation.
     *
     * A run is awaiting a website exactly when it parked at the publish
     * boundary with its CID preserved and no website recorded anywhere locally
     * (neither the run's own publish identifiers nor the persisted identity).
     * That check alone - resumable AND local website_id empty - is what keeps
     * the account registry off every ordinary poll: a run with a known website,
     * or a run that never reached the publish boundary, never reads the list.
     *
     * REGISTRY-FIRST: rather than probing with a blind create/attach 422, the
     * account's website list (GET /api/websites) is the existence check. The
     * workspace publish is targeting the preserved CID, so a listed website
     * whose target_hash matches that CID is this workspace's website - even
     * when our local cache is empty (e.g. the operator created it in Pinner).
     * On a match the matched id is written through to the persisted identity
     * (cache write-through) so subsequent runs behave as linked; only a
     * no-match with no local id stays awaiting.
     *
     * The derivation is a read with a deliberately scoped side effect: the
     * write-through only fires inside the narrow awaiting check, never on the
     * regular poll, and it is idempotent (recording an existing website id).
     */
    private function awaitingWebsiteFor(?ExportRun $run): bool
    {
        // A known destination (on the run or in the identity option) means the
        // workspace is linked - nothing to await, and the account registry
        // must not be consulted.
        if ($run === null || $run->websiteId !== null) {
            return false;
        }
        if ($this->identity->current()?->websiteId !== null) {
            return false;
        }

        // Only a run that parked at the publish boundary, resumable with the
        // CID preserved, is a candidate. The persisted boundary carries the
        // CID even though a resumable outcome never wires the run's top-level
        // publishCid (only a Completed boundary does).
        $boundary = $run->publish;
        if ($boundary === null || $boundary->status !== PublishBoundaryStatus::Resumable) {
            return false;
        }
        $cid = $boundary->cid;
        if ($cid === null || $cid === '') {
            return false;
        }

        // Without the registry adapters there is no existence check to run: fall
        // back to the run's own park signal so the field still reports the
        // wait, without touching the network.
        if ($this->websites === null || $this->registry === null) {
            return true;
        }

        // The match is the shared {@see websiteServingCid()} — the same
        // {@see websiteImmutableCidFor()} registry CID comparison the link
        // picker exclusion and the attach-conflict tolerance reuse — so "the
        // workspace's website" is always decided the same way.
        $matched = $this->websiteServingCid($cid);
        if ($matched === null) {
            return true;
        }

        // Cache write-through: the workspace's website exists in the account
        // even though nothing was recorded locally yet. The list rows carry no
        // name, so the bound domain (or failing that, the id) names the
        // identity — the uniform recordWebsite {@see websiteServingCid()} also
        // feeds the completed guided create/link — so the next status un-parks
        // exactly as if the website had been attached through the card.
        $this->registry->recordWebsite(
            (string) $matched->id(),
            $matched->domain() !== '' ? $matched->domain() : (string) $matched->id(),
        );

        return false;
    }

    /**
     * The first account website whose immutable target equals the preserved
     * CID — this run's website by the same registry match the awaiting
     * derivation uses — or null when none does.
     *
     * An IPNS-targeted website's mutable target_hash (the IPNS name) is
     * resolved to the CID it currently serves before the comparison; a name
     * that is not yet published resolves to null and is treated as no-match.
     * An unreadable registry (HttpException) degrades to null so callers treat
     * it as no-match, exactly like an unrelated website.
     */
    private function websiteServingCid(?string $cid): ?\LumeWeb\Cast\Ipfs\Website
    {
        if ($cid === null || $this->websites === null) {
            return null;
        }

        try {
            foreach ($this->websites->list() as $website) {
                if ($this->websiteImmutableCidFor($website) === $cid) {
                    return $website;
                }
            }
        } catch (HttpException $exception) {
            // The registry could not be read; the "is it this website"
            // question cannot be answered, so treat it as no-match (the
            // awaiting derivation keeps waiting, the conflict stands).
        }

        return null;
    }
}
