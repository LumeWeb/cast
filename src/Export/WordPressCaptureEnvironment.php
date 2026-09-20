<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * WordPress adapter for the capture {@see CaptureEnvironment} interface: builds a
 * real per-run {@see CaptureService} from the live WordPress HTTP stack
 * ({@see WordPressCaptureHttp} behind {@see WordPressCaptureTransport}), the
 * jailed local asset source ({@see WordPressAssetFileSystem} over the live
 * uploads/content/plugins/themes roots), and an atomic
 * {@see LocalOutputFileSystem} over the run's work directory.
 *
 * Capture is strictly anonymous exactly like the probe: it never sources,
 * stores or attaches credentials — the deployment's Caddy bypasses Basic Auth
 * on the plugin's own loopback requests, so a guarded origin still surfaces
 * through capture outcomes instead of echoing any admin credential.
 */
final class WordPressCaptureEnvironment implements CaptureEnvironment
{
    public function captureService(Origin $origin, string $workDir): CaptureService
    {
        $http = new WordPressCaptureHttp();
        $disk = new WordPressAssetFileSystem(WordPressAssetPaths::roots(), $workDir);

        return new CaptureService(
            new WordPressCaptureTransport($http),
            $origin,
            new LocalOutputFileSystem($workDir),
            $disk,
        );
    }
}
