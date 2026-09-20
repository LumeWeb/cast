<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * A work item whose canonical path is the conventional 404 destination is
 * captured into the fixed 404.html file when the server answers 404. Any
 * other 404 fails and deletes stale output.
 */
final class DefaultNotFoundPolicy implements NotFoundPolicy
{
    public const OUTPUT_PATH = '404.html';
    private const DEDICATED_PATHS = ['/404', '/404.html'];

    public function outputPathForDedicated404(WorkItem $item): ?string
    {
        if (in_array(rtrim($item->url()->path(), '/'), self::DEDICATED_PATHS, true)) {
            return self::OUTPUT_PATH;
        }

        return null;
    }
}
