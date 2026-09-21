<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\CaptureSummary;
use LumeWeb\Cast\Export\DiscoverResult;
use LumeWeb\Cast\Export\PackResult;
use LumeWeb\Cast\Export\PipelineStage;
use LumeWeb\Cast\Export\PipelineStageKey;
use LumeWeb\Cast\Export\PipelineState;
use LumeWeb\Cast\Export\ProbeResult;
use LumeWeb\Cast\Export\PublishBoundaryResult;
use LumeWeb\Cast\Export\RewriteSummary;
use LumeWeb\Cast\Export\SetupResult;
use LumeWeb\Cast\Export\StageResult;
use LumeWeb\Cast\Export\WrapupResult;

/**
 * Recording {@see PipelineStage} double that shares the orchestrator's
 * {@see PipelineState}: it remembers what Probe/Setup/Discover/Capture values
 * it observed on every execute (the hydrated view), and can record fresh
 * Probe/Setup/Discover/Capture values into the shared state the way the real
 * stages would.
 */
final class RuntimeStateFakeStage implements PipelineStage
{
    /**
     * The shared-state Probe/Setup/Discover/Capture values this stage observed
     * on the most recent execute — null until the first call.
     */
    public ?ProbeResult $seenProbe = null;

    public ?SetupResult $seenSetup = null;

    public ?DiscoverResult $seenDiscover = null;

    public ?CaptureSummary $seenCapture = null;

    public ?RewriteSummary $seenRewrite = null;

    public ?PackResult $seenPack = null;

    public ?WrapupResult $seenWrapup = null;

    public ?PublishBoundaryResult $seenPublish = null;

    /**
     * When set, the stage records this Probe/Setup/Discover/Capture/Rewrite/
     * Pack/Wrapup/Publish value into the shared state on every execute
     * (simulating a successful real stage).
     */
    public ?ProbeResult $writesProbe = null;

    public ?SetupResult $writesSetup = null;

    public ?DiscoverResult $writesDiscover = null;

    public ?CaptureSummary $writesCapture = null;

    public ?RewriteSummary $writesRewrite = null;

    public ?PackResult $writesPack = null;

    public ?WrapupResult $writesWrapup = null;

    public ?PublishBoundaryResult $writesPublish = null;

    /**
     * @var list<string>
     */
    public array $calls = [];

    public function __construct(
        private readonly PipelineStageKey $stageKey,
        private readonly PipelineState $state,
    ) {
    }

    public function key(): PipelineStageKey
    {
        return $this->stageKey;
    }

    public function execute(string $cursor): StageResult
    {
        $this->calls[] = $cursor;
        $this->seenProbe = $this->state->probe;
        $this->seenSetup = $this->state->setup;
        $this->seenDiscover = $this->state->discover;
        $this->seenCapture = $this->state->capture;
        $this->seenRewrite = $this->state->rewrite;
        $this->seenPack = $this->state->pack;
        $this->seenWrapup = $this->state->wrapup;
        $this->seenPublish = $this->state->publish;

        if ($this->writesProbe !== null) {
            $this->state->probe = $this->writesProbe;
        }
        if ($this->writesSetup !== null) {
            $this->state->setup = $this->writesSetup;
        }
        if ($this->writesDiscover !== null) {
            $this->state->discover = $this->writesDiscover;
        }
        if ($this->writesCapture !== null) {
            $this->state->capture = $this->writesCapture;
        }
        if ($this->writesRewrite !== null) {
            $this->state->rewrite = $this->writesRewrite;
        }
        if ($this->writesPack !== null) {
            $this->state->pack = $this->writesPack;
        }
        if ($this->writesWrapup !== null) {
            $this->state->wrapup = $this->writesWrapup;
        }
        if ($this->writesPublish !== null) {
            $this->state->publish = $this->writesPublish;
        }

        return StageResult::done();
    }
}
