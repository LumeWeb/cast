<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Local-only read boundary for asset work items. Capture prefers a disk copy
 * for assets; a null body means the file is not available locally and capture
 * must fall back to an HTTP fetch. No cookies or auth live in this pure module.
 */
interface DiskAssetSource
{
    public function read(WorkItem $item): ?CaptureBody;
}
