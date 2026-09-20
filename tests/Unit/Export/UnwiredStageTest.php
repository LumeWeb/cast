<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\PipelineStageKey;
use LumeWeb\Cast\Export\UnwiredStage;
use PHPUnit\Framework\TestCase;

/**
 * A stage boundary with no real implementation yet must never silently keep the
 * pipeline alive forever: it reports a clear not-yet-wired failure so the run
 * eventually fails instead of ticking more() indefinitely.
 */
final class UnwiredStageTest extends TestCase
{
    public function testUnwiredStageFailsWithANotWiredReason(): void
    {
        $result = (new UnwiredStage(PipelineStageKey::Capture))->execute('');

        self::assertFalse($result->done);
        self::assertNotNull($result->failure);
        self::assertStringContainsString('capture', $result->failure);
        self::assertStringContainsString('not wired', $result->failure);
    }

    public function testReportsItsBoundaryKey(): void
    {
        self::assertSame(
            PipelineStageKey::Pack,
            (new UnwiredStage(PipelineStageKey::Pack))->key()
        );
    }
}
