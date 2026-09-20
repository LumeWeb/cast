<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Injectable boundary around "is there publishable public content yet?".
 *
 * The first-publish flow stays quiet until at least one publish-eligible
 * public item exists. Implementations answer that cheap readiness question;
 * the WordPress probe ({@see WordPressPublishedContentProbe}) runs a minimal
 * one-row query over published posts/pages and never a heavy full-site scan.
 */
interface PublishedContentProbe
{
    public function hasEligibleContent(): bool;
}
