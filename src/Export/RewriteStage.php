<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

use LumeWeb\Cast\Export\Rewrite\ArrayWarningCollector;
use LumeWeb\Cast\Export\Rewrite\RewriteContext;
use LumeWeb\Cast\Export\Rewrite\WorkItemQueueCollector;

/**
 * The rewrite stage with asset reconciliation: rewrites every completed
 * text-like item, then drains the assets/media those rewrites collected.
 *
 * First pass — post-capture, over completed captured items. Claims exactly one
 * rewritable Done work item per bounded tick (lowest numeric priority first),
 * reads its captured body through the environment, runs the existing
 * {@see RewriteService} with an item-URL {@see RewriteContext} and a canonical
 * {@see WorkItemQueueCollector}, and writes the rewritten body back. The
 * collector captures every in-origin, non-page URL the rewriters surface
 * (images, css/js/fonts, documents — page URLs are dropped: discovery is the
 * only page authority), moving them into the queue until this pass's fixed
 * point — no rewritable Done row remains.
 *
 * Reconciliation pass — bounded. Only when the first pass reaches its fixed
 * point AND pendingCount() > 0 does the stage start claiming collected
 * asset rows, capturing each through the SAME capture machinery the capture
 * stage uses (a {@see CaptureEnvironment} + {@see CaptureService} +
 * {@see CaptureOutcomeApplier}, so retry policy and terminal Failed marking
 * are never duplicated). A captured asset that lands Done and is rewritable is
 * rewritten in the same tick; rewriting it may collect further assets (a css
 * referencing images), which the loop absorbs. Each claimed asset reports one
 * progress unit, first-pass rewrite progress is unchanged, and the stage reports
 * done('') only once pendingCount() === 0 AND no rewritable Done row remains
 * — so the pack validation's fixed-point check holds without any queued work.
 *
 * The only durable cursor state is the cumulative collected-asset count (see
 * {@see assetsCollected} / {@see cursorFor}): the repository itself records
 * which assets were claimed and their statuses, so a fresh stage instance
 * restarting from a persisted run resumes identically. The optional hard cap
 * (defaults to {@see DiscoverStage::DEFAULT_MAX_ITEMS}) refuses further
 * collected assets once the cumulative count is exhausted and surfaces one
 * warning instead of silently losing references; the already-collected backlog
 * still drains to the fixed point. Without a wired capture environment the
 * stage keeps the pre-reconciliation behavior — rewrite, collect, finish — so
 * an unwired run never blocks mid-rewrite. No sleeps, no loops, no direct run
 * mutation — one bounded unit per tick.
 */
final class RewriteStage implements PipelineStage
{
    /**
     * Priority assigned to work items discovered while rewriting a body, so a
     * later reconciliation pass claims them before plain discovery-derived
     * rows.
     */
    private const DISCOVERED_PRIORITY = 1;

    public function __construct(
        private readonly RewriteEnvironment $environment,
        private readonly PipelineState $state,
        private readonly WorkItemRepository $repository,
        private readonly ?CaptureEnvironment $captureEnvironment = null,
        private readonly int $maxAssets = DiscoverStage::DEFAULT_MAX_ITEMS,
        private readonly CaptureOutcomeApplier $outcomeApplier = new CaptureOutcomeApplier(),
    ) {
        if ($maxAssets < 1) {
            throw new \InvalidArgumentException('RewriteStage maxAssets must be >= 1');
        }
    }

    public function key(): PipelineStageKey
    {
        return PipelineStageKey::Rewrite;
    }

    public function execute(string $cursor): StageResult
    {
        $origin = $this->state->probe?->origin;
        if ($origin === null) {
            return StageResult::fail('Rewrite requires a successful probe first.');
        }

        $workDir = $this->state->setup?->workDir;
        if ($workDir === null) {
            return StageResult::fail('Rewrite requires a successful setup first.');
        }

        $assetsCollected = $this->assetsCollected($cursor);

        // First pass — and the rewrite half of the reconciliation pass after a
        // reconciled asset landed Done — rewrites the next completed rewritable
        // item as a single bounded unit. During the first pass this keeps
        // returning pages until
        // the rewritable-Done fixed point is reached, so collected assets are
        // never claimed out of order.
        $item = $this->repository->claimNextRewritable();
        if ($item !== null) {
            return $this->rewriteUnit($item, $origin, $workDir, $assetsCollected);
        }

        // True fixed point: nothing rewritable remains and nothing is pending.
        if ($this->repository->pendingCount() === 0) {
            $this->state->rewrite = $this->summary($assetsCollected);

            return StageResult::done('', 0);
        }

        // Collected assets await reconciliation. Without a wired capture
        // environment the stage keeps the pre-reconciliation behaviour — leave
        // the collected queue for a later wiring and finish — so an unwired
        // run is never blocked mid-rewrite.
        if ($this->captureEnvironment === null) {
            $this->state->rewrite = $this->summary($assetsCollected);

            return StageResult::done('', 0);
        }

        return $this->reconcileUnit($origin, $workDir, $assetsCollected, $this->captureEnvironment);
    }

    /**
     * Rewrites the next rewritable Done item as one bounded unit. A
     * missing/unreadable body fails this tick without consuming the row — the
     * claim is a non-destructive peek — so once the body exists a later tick
     * rewrites it. Includes the output path in the reason so the failure is
     * actionable.
     */
    private function rewriteUnit(WorkItem $item, Origin $origin, string $workDir, int $assetsCollected): StageResult
    {
        try {
            [$usedAfter, $warnings] = $this->rewriteBody($item, $origin, $workDir, $assetsCollected);
        } catch (\Throwable $exception) {
            return StageResult::fail(sprintf(
                'Captured body is missing for %s: %s',
                $item->outputPath(),
                $exception->getMessage(),
            ));
        }

        return StageResult::more($this->cursorFor($usedAfter), 1, $warnings);
    }

    /**
     * Drains one collected asset row per bounded tick: claims it, captures it
     * through the shared capture machinery, and — when it lands Done and is
     * text-like — rewrites it in the same tick so secondary assets it
     * references are absorbed. Every claimed asset reports one progress unit;
     * a retry that is not yet due waits without making progress.
     */
    private function reconcileUnit(
        Origin $origin,
        string $workDir,
        int $assetsCollected,
        CaptureEnvironment $capture,
    ): StageResult {
        $item = $this->repository->claimNext();
        if ($item === null) {
            // Nothing is claimable (e.g. a scheduled retry is not due yet):
            // stay open without spinning, exactly like the capture stage waits.
            return StageResult::more($this->cursorFor($assetsCollected), 0);
        }

        $result = $capture->captureService($origin, $workDir)->capture($item);
        $this->outcomeApplier->apply($this->repository, $item, $result);

        // A successful capture that is text-like is rewritten immediately so
        // one reconciled asset is never split across two progress units;
        // binary/fixed assets and terminal failures stop here (they remain
        // Done or Failed and never block convergence).
        if (!$this->landsDone($result->outcome) || !$this->isRewritable($item)) {
            return StageResult::more($this->cursorFor($assetsCollected), 1);
        }

        try {
            [$usedAfter, $warnings] = $this->rewriteBody($item, $origin, $workDir, $assetsCollected);
        } catch (\Throwable $exception) {
            return StageResult::fail(sprintf(
                'Reconciled asset body is missing for %s: %s',
                $item->outputPath(),
                $exception->getMessage(),
            ));
        }

        return StageResult::more($this->cursorFor($usedAfter), 1, $warnings);
    }

    /**
     * Rewrites one captured item's body through the environment, funnelling
     * every discovered in-origin candidate through a cap-aware collector, then
     * moves the row to Rewritten. Reads the body first so a missing body
     * surfaces before any side effect; the caller decides how to fail without
     * consuming the row.
     *
     * @return array{0: int, 1: list<string>} [assetsCollectedAfter, warnings]
     * @throws \Throwable when the captured body is missing/unreadable
     */
    private function rewriteBody(WorkItem $item, Origin $origin, string $workDir, int $assetsCollected): array
    {
        $body = $this->environment->readBody($item->outputPath());

        $warnings = new ArrayWarningCollector();
        $collector = new WorkItemQueueCollector(
            $this->repository,
            self::DISCOVERED_PRIORITY,
            $this->maxAssets,
            $assetsCollected,
        );
        $context = new RewriteContext(
            $item->url(),
            $origin,
            $collector,
        );
        $rewritten = $this->environment
            ->rewriteService($origin, $workDir)
            ->rewrite($item, $body, $context, $warnings);

        $this->environment->writeBody($item->outputPath(), $rewritten);
        $this->repository->transition($item->urlHash(), WorkItemStatus::Rewritten);

        $usedAfter = $assetsCollected + $collector->collectedCount();
        $surface = $this->surfaceWarnings($warnings);

        // The cumulative cap refused at least one brand-new asset on this tick
        // (assetsCollected < maxAssets only holds on the crossing tick, so the
        // warning is recorded exactly once; the cursor already carries the
        // capped count for every later tick).
        if ($collector->capHit() && $assetsCollected < $this->maxAssets) {
            $surface[] = sprintf(
                'Rewriting refused further assets: the cap of %d collected assets was reached.',
                $this->maxAssets,
            );
        }

        return [$usedAfter, $surface];
    }

    /**
     * Whether a capture outcome leaves the row Done (the three statuses the
     * shared applier maps to Done), kept in step with {@see CaptureOutcomeApplier}.
     */
    private function landsDone(CaptureOutcome $outcome): bool
    {
        return in_array($outcome, [CaptureOutcome::Copied, CaptureOutcome::Fetched, CaptureOutcome::Redirected], true);
    }

    /**
     * Whether a reconciled item is text-like and therefore rewritten in-tick.
     * Mirrors the repository's rewritable predicate; reconciliation hands back
     * only collected non-page rows (asset/text), so a narrow text check
     * suffices.
     */
    private function isRewritable(WorkItem $item): bool
    {
        return $item->kind() === WorkItemKind::Text || $this->isTextAsset($item);
    }

    private function isTextAsset(WorkItem $item): bool
    {
        $extension = strtolower(pathinfo($item->outputPath(), PATHINFO_EXTENSION));

        return in_array($extension, ['css', 'js', 'mjs', 'json', 'xml', 'rss', 'atom'], true);
    }

    /**
     * The durable cursor payload: the cumulative count of distinct assets
     * collected so far. Empty stays the pre-reconciliation empty cursor, so
     * the common no-asset export keeps its exact previous cursor shape.
     */
    private function cursorFor(int $assetsCollected): string
    {
        return $assetsCollected > 0 ? (string) $assetsCollected : '';
    }

    private function assetsCollected(string $cursor): int
    {
        if ($cursor === '' || !ctype_digit($cursor)) {
            return 0;
        }

        return (int) $cursor;
    }

    /**
     * Reads the terminal summary from the repository at completion so a fresh
     * stage instance restarting from a persisted run reports the same counts:
     * Rewritten rows plus the Done rows still passed through untouched. The
     * cumulative collected-asset count the cursor carries is pinned onto the
     * summary too, so the dashboard's denominator can still count the
     * collected work once the cursor moves on to pack.
     */
    private function summary(int $assetsCollected): RewriteSummary
    {
        return new RewriteSummary(
            $this->repository->countByStatus(WorkItemStatus::Rewritten),
            $this->repository->countByStatus(WorkItemStatus::Done),
            assetsCollected: $assetsCollected,
        );
    }

    /**
     * @return list<string> human-readable warning messages to surface on the
     *                      stage result, in record order
     */
    private static function surfaceWarnings(ArrayWarningCollector $warnings): array
    {
        return array_map(
            static fn (array $warning): string => $warning['message'],
            $warnings->all(),
        );
    }
}
