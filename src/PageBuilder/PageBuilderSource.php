<?php

declare(strict_types=1);

namespace LumeWeb\Cast\PageBuilder;

/**
 * The distribution source a curated page-builder candidate is delivered from.
 *
 * This is pure data — a closed set of source identifiers, not a provider
 * abstraction or a strategy. The only source the current installer can drive is
 * WordPress.org (core's wp.updates.installPlugin installs wp.org plugin slugs).
 * Any other source is a deliberate seam for future handling: an installable
 * candidate whose source the installer does not support is refused, never
 * silently installed from the wrong place.
 */
enum PageBuilderSource: string
{
    /** Installable via core wp.updates.installPlugin from wordpress.org. */
    case WordPressOrg = 'wordpress.org';

    /**
     * Commercial / premium distribution that core's wp.org install flow cannot
     * drive. Kept solely to make the unsupported-source rejection a real,
     * testable branch rather than a dead statement.
     */
    case Premium = 'premium';
}
