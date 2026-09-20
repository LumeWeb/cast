<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The pack stage: post-rewrite, over the completed work tree. Runs the
 * pre-pack validation (fixed point, root index, output-path integrity,
 * leftover origins / broken references) exactly once per run, drives the
 * existing {@see ZipPackager} to produce the artifact ZIP plus its adjacent
 * manifest, and records the resulting {@see PackResult} into
 * {@see PipelineState::$pack}.
 *
 * One bounded unit per tick: validation and packing happen in a single call
 * and the stage reports done('') at its fixed point — the artifact is written.
 * An empty work tree is not a crash: it still produces the inspectable empty
 * ZIP + manifest (22-byte EOCD, with the manifest describing the empty
 * artifact). A hard integrity violation (pending items, unsafe/duplicate
 * paths, missing or invalid root index, strict-mode escalation) blocks packing
 * with no ZIP; a fatal packer problem (missing ext-zip, missing work dir,
 * in-jail artifact path) fails the tick. Warnable findings (leftover origins,
 * broken references) fold into the packer warnings and are surfaced on the
 * result so the run records them. No sleeps, no loops, no direct run mutation.
 */
final class PackStage implements PipelineStage
{
    public function __construct(
        private readonly PackEnvironment $environment,
        private readonly PipelineState $state,
        private readonly WorkItemRepository $repository,
        private readonly string $runId,
    ) {
    }

    public function key(): PipelineStageKey
    {
        return PipelineStageKey::Pack;
    }

    public function execute(string $cursor): StageResult
    {
        $origin = $this->state->probe?->origin;
        if ($origin === null) {
            return StageResult::fail('Pack requires a successful probe first.');
        }

        $workDir = $this->state->setup?->workDir;
        if ($workDir === null) {
            return StageResult::fail('Pack requires a successful setup first.');
        }

        $tree = new DirectoryArtifactTree($workDir);
        $report = (new ArtifactValidator(
            $tree,
            new RepositoryWorkItemStateProvider($this->repository),
            $origin,
            $this->environment->validationMode(),
        ))->validate();

        $zipPath = $this->environment->artifactPath($this->runId);

        // A hard integrity violation blocks packing with no ZIP — except the
        // empty artifact, where the root index is legitimately absent and the
        // run still produces the inspectable empty ZIP + manifest.
        if ($report->status === PackStatus::Failed && !$this->emptyArtifact($tree, $report)) {
            $this->state->pack = PackResult::failed($zipPath, self::errorMessages($report));

            return StageResult::fail($this->failureReason($report));
        }

        $result = (new ZipPackager())->pack(
            $workDir,
            $zipPath,
            ['run_id' => $this->runId, 'origin' => (string) $origin],
            self::warningMessages($report),
        );

        if ($result->status === PackStatus::Failed) {
            $this->state->pack = $result;

            return StageResult::fail(implode('; ', $result->warnings));
        }

        $this->state->pack = $result;

        return StageResult::done('', 0, $result->warnings);
    }

    /**
     * Whether a failed report is the tolerated empty artifact: no staged files
     * at all and the only hard findings are the missing/ghost root index.
     * Pending items, unsafe or duplicate paths and strict-mode escalations are
     * never tolerated — they still block packing.
     */
    private function emptyArtifact(ArtifactTree $tree, ValidationReport $report): bool
    {
        if ($tree->paths() !== []) {
            return false;
        }

        foreach ($report->errors() as $error) {
            if (!in_array($error->category, ['missing_root_index', 'ghost_root_index'], true)) {
                return false;
            }
        }

        return $report->errors() !== [];
    }

    private function failureReason(ValidationReport $report): string
    {
        $errors = $report->errors();
        $first = $errors[0] ?? null;

        return $first === null ? 'Pre-pack validation failed.' : $first->message;
    }

    /**
     * @return list<string>
     */
    private static function warningMessages(ValidationReport $report): array
    {
        return array_map(
            static fn (ValidationFinding $finding): string => $finding->message,
            $report->warnings(),
        );
    }

    /**
     * @return list<string>
     */
    private static function errorMessages(ValidationReport $report): array
    {
        return array_map(
            static fn (ValidationFinding $finding): string => $finding->message,
            $report->errors(),
        );
    }
}
