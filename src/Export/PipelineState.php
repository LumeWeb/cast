<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Shared, in-memory state carried forward between pipeline stages for one
 * export run. Stages record the outputs later stages depend on here so the
 * orchestrator does not have to re-derive expensive facts (e.g. the canonical
 * origin the probe learned and setup/discovery must build on).
 *
 * The object is intentionally a mutable holder: each stage fills the slot it
 * owns and never touches the others. Nothing secret ever lands here.
 */
final class PipelineState
{
    /**
     * Filled by {@see ProbeStage} once the home page answered with a plausible
     * 200 or an acceptable canonical redirect; null before/unless the probe
     * succeeds.
     */
    public ?ProbeResult $probe = null;

    /**
     * Filled by {@see SetupStage} once the jailed, web-denied work directory
     * exists; null before/unless setup succeeds.
     */
    public ?SetupResult $setup = null;

    /**
     * Filled by {@see DiscoverStage} once every producer cursor has drained;
     * null before/unless discovery succeeds.
     */
    public ?DiscoverResult $discover = null;

    /**
     * Filled by {@see CaptureStage} once the work queue reaches its fixed
     * point (no queued/processing/retry-due rows remain); null before/unless
     * capture completes.
     */
    public ?CaptureSummary $capture = null;

    /**
     * Filled by {@see RewriteStage} once every completed rewritable item has
     * been rewritten (no rewritable Done row remains); null before/unless
     * rewrite completes.
     */
    public ?RewriteSummary $rewrite = null;

    /**
     * Filled by {@see PackStage} once the artifact ZIP and its adjacent
     * manifest are written (or packing failed and the run recorded the
     * failure); null before/unless pack executes.
     */
    public ?PackResult $pack = null;

    /**
     * Filled by {@see WrapupStage} once the artifact passed its integrity
     * check and the jailed work directory was safely deleted (or a wrap-up
     * failure was recorded); null before/unless wrap-up executes.
     */
    public ?WrapupResult $wrapup = null;

    /**
     * Filled by {@see PublishStage} once the artifact was published (or the
     * publish failed/stalled and the run recorded the outcome); null
     * before/unless publish executes.
     */
    public ?PublishBoundaryResult $publish = null;
}
