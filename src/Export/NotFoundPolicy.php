<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Decides what an HTTP 404 means for an item: an ordinary lost page fails,
 * while a deliberately captured 404 destination is written to a fixed file so
 * the offline site keeps its "not found" page.
 */
interface NotFoundPolicy
{
    /**
     * The relative output path for the dedicated 404 page, or null when this
     * item is not the dedicated 404 destination.
     */
    public function outputPathForDedicated404(WorkItem $item): ?string;
}
