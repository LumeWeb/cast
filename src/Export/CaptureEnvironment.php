<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * WordPress facts the capture stage needs, isolated behind an interface so the
 * pure {@see CaptureStage} never loads WordPress. A concrete WordPress adapter
 * later builds a real transport (wp_remote_get) and a real disk asset source;
 * unit tests inject a scripted fake instead. The per-run origin and work
 * directory reach the service through the stage, so this interface only wires
 * the transport/disk parts that are deployment-specific.
 */
interface CaptureEnvironment
{
    /**
     * Build the pure per-item {@see CaptureService} for one run: a transport,
     * the jailed disk asset source, and an atomic output filesystem over the
     * given work directory. The stage calls this per claim and never touches
     * construction details.
     */
    public function captureService(Origin $origin, string $workDir): CaptureService;
}
