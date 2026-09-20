<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * WordPress-aware {@see DiskAssetSource}. Resolves an asset URL to the most
 * specific jail root (uploads/content/plugins/themes/ABSPATH wp-includes),
 * rejects encoded separators/NUL/control/backslash/dot segments and symlink
 * escapes, excludes the export work directory, and copies a snapshot of the
 * local file into a temporary stream so capture sees a stable body. A missing,
 * escaped, denied or unmappable file reads as null and CaptureService falls
 * back to HTTP.
 */
final class WordPressAssetFileSystem implements DiskAssetSource
{
    public function __construct(
        private readonly WordPressAssetRoots $roots,
        private readonly ?string $workDir = null,
    ) {
    }

    public function read(WorkItem $item): ?CaptureBody
    {
        $mapped = $this->roots->map($item->url()->path());
        if ($mapped === null) {
            return null;
        }

        $file = JailedPath::resolve($mapped['relative'], $mapped['root']);
        if ($file === null || $this->isInsideWorkDir($file)) {
            return null;
        }

        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }

        $temp = fopen('php://temp', 'wb+');
        if ($temp === false) {
            fclose($handle);

            return null;
        }
        stream_copy_to_stream($handle, $temp);
        fclose($handle);
        rewind($temp);

        return CaptureBody::fromStream($temp);
    }

    private function isInsideWorkDir(string $file): bool
    {
        if ($this->workDir === null) {
            return false;
        }

        $workReal = realpath($this->workDir);

        return $workReal !== false && (
            $file === $workReal || str_starts_with($file, rtrim($workReal, '/') . DIRECTORY_SEPARATOR)
        );
    }
}
