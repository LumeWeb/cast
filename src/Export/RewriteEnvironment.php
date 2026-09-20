<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

use LumeWeb\Cast\Export\Rewrite\RewriteService;

/**
 * WordPress facts the rewrite stage needs, isolated behind an interface so the
 * pure {@see RewriteStage} never loads WordPress. The stage reaches the real
 * per-run {@see RewriteService} plus the captured-body content seams (read the
 * body capture wrote, write the rewritten body back, both inside the jailed
 * work directory). A concrete WordPress adapter later wires a real service
 * over the run's work directory; unit tests inject a scripted fake instead.
 */
interface RewriteEnvironment
{
    /**
     * Build the pure per-run {@see RewriteService} for one run. The stage
     * calls this once per processed item and never touches construction
     * details.
     */
    public function rewriteService(Origin $origin, string $workDir): RewriteService;

    /**
     * Read the captured body of one completed work item from the run's work
     * directory. Throws when the body is missing or unreadable.
     */
    public function readBody(string $relativePath): string;

    /**
     * Persist the rewritten body for one work item back into the run's work
     * directory, atomically.
     */
    public function writeBody(string $relativePath, string $contents): void;
}
