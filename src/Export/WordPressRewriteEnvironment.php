<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

use LumeWeb\Cast\Export\Rewrite\RewriteService;

/**
 * WordPress adapter for the rewrite {@see RewriteEnvironment} interface: builds a
 * real per-run {@see RewriteService} and reaches the captured-body file seams
 * over a single run's jailed work directory.
 *
 * Body reads go through the {@see JailedPath} jail — a raw or encoded dot
 * segment, an absolute escape, a C0/control byte or a symlink pointing outside
 * the work directory all resolve to null and fail the read loudly, so the
 * rewrite stage can never read a file outside the run. Body writes reuse the
 * atomic {@see LocalOutputFileSystem} convention the capture stage already
 * uses (temp sibling + rename, dot segments refused), so a crash never leaves
 * a half-written rewritten body.
 *
 * The adapter is constructed per run at tick time with that run's work
 * directory — the read/write seams carry no work-directory argument of their
 * own — exactly like the per-run Setup wiring.
 */
final class WordPressRewriteEnvironment implements RewriteEnvironment
{
    public function __construct(
        private readonly string $workDir,
    ) {
    }

    public function rewriteService(Origin $origin, string $workDir): RewriteService
    {
        return new RewriteService();
    }

    public function readBody(string $relativePath): string
    {
        $real = JailedPath::resolve($relativePath, $this->workDir);
        if ($real === null) {
            throw new \RuntimeException(sprintf(
                'Captured body is missing or unsafe for %s',
                $relativePath,
            ));
        }

        $body = @file_get_contents($real);
        if ($body === false) {
            throw new \RuntimeException(sprintf(
                'Captured body is unreadable for %s',
                $relativePath,
            ));
        }

        return $body;
    }

    public function writeBody(string $relativePath, string $contents): void
    {
        (new LocalOutputFileSystem($this->workDir))->put($relativePath, $contents);
    }
}
