<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

use Closure;
use InvalidArgumentException;

/**
 * Dependency-injected composition root for the export pipeline.
 *
 * Holds the pure collaborators the orchestrator needs: the {@see RunRepository}
 * it persists every mutation through, the ordered {@see PipelineStage}
 * implementations keyed by their {@see PipelineStageKey} string value, the one
 * shared {@see PipelineState} every stage records into (and the orchestrator
 * re-hydrates from the persisted run on each tick), and optional per-run stage
 * factories for boundaries that need the current run id (e.g. Setup).
 *
 * Every boundary must be wired — as a static stage, an {@see UnwiredStage}, or
 * a per-run factory — a missing stage is a wiring error and is rejected loudly
 * at construction time.
 */
final class PipelineContext
{
    public readonly PipelineState $state;

    /**
     * @param array<string, PipelineStage>                            $stages         keyed by PipelineStageKey value
     * @param array<string, Closure(string, ?RunSettings): PipelineStage> $stageFactories keyed by PipelineStageKey value
     */
    public function __construct(
        public readonly RunRepository $runs,
        public readonly array $stages,
        ?PipelineState $state = null,
        public readonly array $stageFactories = [],
    ) {
        $this->state = $state ?? new PipelineState();

        foreach (PipelineStageKey::cases() as $key) {
            if (!isset($this->stages[$key->value]) && !isset($this->stageFactories[$key->value])) {
                throw new InvalidArgumentException(sprintf(
                    'PipelineContext is missing the %s stage.',
                    $key->value,
                ));
            }
        }
    }

    /**
     * The stage for a boundary. A per-run factory receives the current run id
     * and its persisted settings snapshot so it can build a run-scoped stage
     * (e.g. Setup with its work directory, or Publish deriving the artifact's
     * target type from the run settings); otherwise the static stage map is
     * used.
     */
    public function stage(PipelineStageKey $key, string $runId, ?RunSettings $settings = null): PipelineStage
    {
        $factory = $this->stageFactories[$key->value] ?? null;
        if ($factory !== null) {
            $stage = $factory($runId, $settings);
            if (!$stage instanceof PipelineStage) {
                throw new \RuntimeException(sprintf(
                    'The %s stage factory did not build a PipelineStage.',
                    $key->value,
                ));
            }

            return $stage;
        }

        $stage = $this->stages[$key->value] ?? null;
        if (!$stage instanceof PipelineStage) {
            throw new \RuntimeException(sprintf('No pipeline stage wired for %s.', $key->value));
        }

        return $stage;
    }
}
