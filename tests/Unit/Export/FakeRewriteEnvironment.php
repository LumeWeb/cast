<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\Rewrite\RewriteService;
use LumeWeb\Cast\Export\RewriteEnvironment;

/**
 * Scripted {@see RewriteEnvironment} fake so the pure rewrite stage is
 * exercised without any WordPress adapter. Holds scripted captured bodies and
 * records every body the stage writes back; readBody() throws for a path with
 * no scripted body so a missing-body tick is easy to stage.
 */
final class FakeRewriteEnvironment implements RewriteEnvironment
{
    /**
     * @param array<string, string> $bodies relative output path => captured body
     */
    public function __construct(
        public array $bodies = [],
    ) {
    }

    /** @var array<string, string> relative output path => rewritten body */
    public array $written = [];

    public function rewriteService(Origin $origin, string $workDir): RewriteService
    {
        return new RewriteService();
    }

    public function readBody(string $relativePath): string
    {
        if (!array_key_exists($relativePath, $this->bodies)) {
            throw new \RuntimeException(sprintf('Captured body is missing for %s', $relativePath));
        }

        return $this->bodies[$relativePath];
    }

    public function writeBody(string $relativePath, string $contents): void
    {
        $this->written[$relativePath] = $contents;
    }
}
