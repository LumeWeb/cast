<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\CaptureEnvironment;
use LumeWeb\Cast\Export\CaptureService;
use LumeWeb\Cast\Export\CaptureTransport;
use LumeWeb\Cast\Export\DiskAssetSource;
use LumeWeb\Cast\Export\LocalOutputFileSystem;
use LumeWeb\Cast\Export\Origin;

/**
 * Scripted {@see CaptureEnvironment} fake so the pure capture stage is
 * exercised against the real {@see CaptureService} without any WordPress
 * adapter. Holds the scripted transport and jailed disk source;
 * captureService() wires a fully configured service over the run origin and
 * an atomic output filesystem on the given work directory.
 */
final class FakeCaptureEnvironment implements CaptureEnvironment
{
    public function __construct(
        private readonly CaptureTransport $transport,
        private readonly DiskAssetSource $disk,
    ) {
    }

    public function captureService(Origin $origin, string $workDir): CaptureService
    {
        return new CaptureService(
            $this->transport,
            $origin,
            new LocalOutputFileSystem($workDir),
            $this->disk,
        );
    }
}
