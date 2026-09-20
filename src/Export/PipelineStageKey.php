<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The fine-grained pipeline boundaries of one export run.
 *
 * The pipeline is a strict forward-only progression — probe → setup →
 * discover → capture → rewrite → pack → wrapup → publish — so a run can never
 * regress or skip a step, and each boundary maps onto the coarse
 * {@see RunStage} bucket the {@see ExportRun} aggregate persists
 * (export/upload/publish). The exact position within a bucket is carried by
 * the run's resume cursor.
 */
enum PipelineStageKey: string
{
    case Probe = 'probe';
    case Setup = 'setup';
    case Discover = 'discover';
    case Capture = 'capture';
    case Rewrite = 'rewrite';
    case Pack = 'pack';
    case Wrapup = 'wrapup';
    case Publish = 'publish';

    /**
     * Position in the pipeline; the first stage is position zero.
     */
    public function order(): int
    {
        return match ($this) {
            self::Probe => 0,
            self::Setup => 1,
            self::Discover => 2,
            self::Capture => 3,
            self::Rewrite => 4,
            self::Pack => 5,
            self::Wrapup => 6,
            self::Publish => 7,
        };
    }

    /**
     * The immediately following boundary, or null for the final stage.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::Probe => self::Setup,
            self::Setup => self::Discover,
            self::Discover => self::Capture,
            self::Capture => self::Rewrite,
            self::Rewrite => self::Pack,
            self::Pack => self::Wrapup,
            self::Wrapup => self::Publish,
            self::Publish => null,
        };
    }

    /**
     * The coarse persisted {@see RunStage} this boundary belongs to.
     */
    public function coarseRunStage(): RunStage
    {
        return match ($this) {
            self::Probe, self::Setup, self::Discover, self::Capture, self::Rewrite => RunStage::Exporting,
            self::Pack => RunStage::Uploading,
            self::Wrapup, self::Publish => RunStage::Publishing,
        };
    }
}
