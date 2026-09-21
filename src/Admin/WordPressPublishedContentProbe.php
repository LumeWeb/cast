<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * WordPress {@see PublishedContentProbe} backed by a single bounded get_posts()
 * existence query.
 *
 * The probe asks for at most one published post/page id so readiness is
 * answered cheaply and never by a heavy full-site scan or export. Requiring
 * the `publish` status excludes drafts, pending, private and autosaved
 * content; restricting post types to post/page excludes revisions and
 * attachments. get_posts() is called through the bootstrap shim in tests and
 * resolves to core in production, so no extra WordPress boundary object is
 * needed — this stays a pure existence probe.
 */
final class WordPressPublishedContentProbe implements PublishedContentProbe
{
    /**
     * The public content kinds that count as publish eligible.
     *
     * @var list<string>
     */
    public const POST_TYPES = ['post', 'page'];

    public function hasEligibleContent(): bool
    {
        $ids = get_posts([
            'post_type' => self::POST_TYPES,
            'post_status' => 'publish',
            'numberposts' => 1,
            'fields' => 'ids',
        ]);

        return $ids !== [];
    }
}
