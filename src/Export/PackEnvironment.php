<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Deployment facts the pack stage needs, isolated behind an interface so the
 * pure {@see PackStage} never loads WordPress. The stage asks only where the
 * per-run artifact ZIP is written and how strict the pre-pack validation
 * must be; a concrete WordPress adapter later resolves the artifact path from
 * the uploads directory (a denied sibling of the work dir) and reads the
 * strict/warning setting. Unit tests inject a scripted fake instead.
 */
interface PackEnvironment
{
    /**
     * The absolute destination of the artifact ZIP for one run, always
     * outside the run's work directory (a denied sibling dir such as
     * {uploads}/cast-exports/{run_id}.zip).
     */
    public function artifactPath(string $runId): string;

    /**
     * The strictness the pre-pack validation applies: warning mode lets
     * warnable findings finish as completed_with_warnings, strict mode
     * escalates them to a hard failure that blocks packing.
     */
    public function validationMode(): ArtifactValidationMode;
}
