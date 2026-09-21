<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\Rewrite\RewriteService;
use LumeWeb\Cast\Export\RewriteEnvironment;

/**
 * {@see RewriteEnvironment} fake backed by the real jailed work directory, so
 * the asset-reconciliation stage exercises the exact file hand-off the pipeline
 * uses: the capture service writes a collected asset into the work directory
 * and the rewrite stage reads/writes the same file. readBody() throws for a
 * path with no staged file, so a missing captured body is easy to stage.
 */
final class FilesystemRewriteEnvironment implements RewriteEnvironment
{
    /** @var array<string, string> relative output path => rewritten body */
    public array $written = [];

    public function __construct(private readonly string $workDir)
    {
    }

    public function rewriteService(Origin $origin, string $workDir): RewriteService
    {
        return new RewriteService();
    }

    public function readBody(string $relativePath): string
    {
        $path = $this->workDir . '/' . $relativePath;
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Captured body is missing for %s', $relativePath));
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Captured body is unreadable for %s', $relativePath));
        }

        return $contents;
    }

    public function writeBody(string $relativePath, string $contents): void
    {
        $path = $this->workDir . '/' . $relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
        $this->written[$relativePath] = $contents;
    }
}
