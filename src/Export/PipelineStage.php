<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * One bounded unit of pipeline work for a single {@see PipelineStageKey}
 * boundary, injected into the {@see \LumeWeb\Cast\Jobs\ExportPipelineTick}
 * orchestrator.
 *
 * A stage performs exactly one unit per call and reports a {@see StageResult}:
 * it never loops over unbounded work, never sleeps, and never mutates the run
 * aggregate itself — the orchestrator applies the returned cursor, progress
 * and warnings to the run and persists them.
 */
interface PipelineStage
{
    /**
     * The pipeline boundary this stage implements.
     */
    public function key(): PipelineStageKey;

    /**
     * Execute exactly one bounded unit of work for this stage.
     *
     * @param string $cursor The stage's opaque resume cursor, or '' the first
     *                       time the stage is reached.
     */
    public function execute(string $cursor): StageResult;
}
