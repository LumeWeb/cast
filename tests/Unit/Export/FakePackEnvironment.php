<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\ArtifactValidationMode;
use LumeWeb\Cast\Export\PackEnvironment;

/**
 * Scripted {@see PackEnvironment} fake so the pure pack stage is exercised
 * without any WordPress adapter. Resolves the per-run artifact zip inside a
 * configured directory (with an override so tests can force a fatal in-jail
 * artifact path) and reports the configured validation strictness.
 */
final class FakePackEnvironment implements PackEnvironment
{
    public function __construct(
        private readonly string $artifactDir,
        private readonly ArtifactValidationMode $mode = ArtifactValidationMode::Warning,
        private readonly ?string $zipPathOverride = null,
    ) {
    }

    public function artifactPath(string $runId): string
    {
        return $this->zipPathOverride ?? $this->artifactDir . '/' . $runId . '.zip';
    }

    public function validationMode(): ArtifactValidationMode
    {
        return $this->mode;
    }
}
