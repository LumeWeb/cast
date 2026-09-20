<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\PipelineStageKey;
use LumeWeb\Cast\Export\RunStage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The fine-grained pipeline boundaries (probe → setup → discover → capture →
 * rewrite → pack → wrapup → publish) are a strict forward-only progression
 * that maps onto the coarse {@see RunStage} buckets the aggregate persists.
 */
final class PipelineStageKeyTest extends TestCase
{
    public function testStagesAreInDeclarationOrderWithForwardOnlyNext(): void
    {
        $ordered = [
            PipelineStageKey::Probe,
            PipelineStageKey::Setup,
            PipelineStageKey::Discover,
            PipelineStageKey::Capture,
            PipelineStageKey::Rewrite,
            PipelineStageKey::Pack,
            PipelineStageKey::Wrapup,
            PipelineStageKey::Publish,
        ];

        foreach ($ordered as $index => $key) {
            self::assertSame($index, $key->order());
            self::assertSame($ordered[$index + 1] ?? null, $key->next());
        }

        self::assertNull(PipelineStageKey::Publish->next());
    }

    #[DataProvider('coarseStageProvider')]
    public function testCoarseRunStageForEachGroup(PipelineStageKey $key, RunStage $expected): void
    {
        self::assertSame($expected, $key->coarseRunStage());
    }

    /**
     * @return array<string, array{PipelineStageKey, RunStage}>
     */
    public static function coarseStageProvider(): array
    {
        return [
            'probe is exporting' => [PipelineStageKey::Probe, RunStage::Exporting],
            'setup is exporting' => [PipelineStageKey::Setup, RunStage::Exporting],
            'discover is exporting' => [PipelineStageKey::Discover, RunStage::Exporting],
            'capture is exporting' => [PipelineStageKey::Capture, RunStage::Exporting],
            'rewrite is exporting' => [PipelineStageKey::Rewrite, RunStage::Exporting],
            'pack is uploading' => [PipelineStageKey::Pack, RunStage::Uploading],
            'wrapup is publishing' => [PipelineStageKey::Wrapup, RunStage::Publishing],
            'publish is publishing' => [PipelineStageKey::Publish, RunStage::Publishing],
        ];
    }
}
