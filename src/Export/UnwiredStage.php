<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Safe no-op placeholder for a pipeline boundary that has no real
 * implementation wired yet.
 *
 * Instead of silently returning more() forever — which would spin the tick
 * without ever finishing — reaching an unwired stage reports a clear
 * not-yet-wired failure, so the run fails with an actionable reason instead of
 * hanging.
 */
final class UnwiredStage implements PipelineStage
{
    public function __construct(private readonly PipelineStageKey $key)
    {
    }

    public function key(): PipelineStageKey
    {
        return $this->key;
    }

    public function execute(string $cursor): StageResult
    {
        return StageResult::fail(sprintf('%s stage is not wired yet', $this->key->value));
    }
}
