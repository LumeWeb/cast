<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * WordPress {@see PublishedContentProbe} backed by a single bounded get_posts()
 * query over published posts and pages.
 *
 * The probe asks WordPress for the published post/page rows so readiness is
 * answered cheaply and never by a heavy full-site scan or export. Requiring
 * the `publish` status excludes drafts, pending, private and autosaved
 * content; restricting post types to post/page excludes revisions and
 * attachments.
 *
 * A freshly installed WordPress site ships with two published factory items —
 * the "Hello world!" post and the "Sample Page" page — created by
 * wp_install_defaults(). Treating their mere presence as readiness would make
 * the first-publish flow light up on a brand-new, content-less site (a false
 * positive). The factory items are therefore identified STRUCTURALLY, by the
 * post IDs wp_install_defaults() creates first on a fresh install (the first
 * post, ID 1, and the first page, ID 2) — never by title equality. WordPress
 * post IDs are auto-increment and never reused, so while those rows exist
 * they ARE the factory content, in any locale (a localized fresh install is
 * recognized without a translation lookup). A genuine user post titled
 * exactly 'Hello world!' carries a later ID and remains eligible.
 *
 * get_posts() is called through the bootstrap shim in tests and resolves to
 * core in production, so no extra WordPress boundary object is needed — this
 * stays a pure existence probe.
 *
 * Important: WordPress get_posts() returns WP_Post objects (or arrays) with
 * `ID`, `post_type` and `post_title` properties, NOT an id=>title map, even
 * when `fields => 'id=>title'` is requested. The probe therefore reads the
 * structural columns from each returned row.
 */
final class WordPressPublishedContentProbe implements PublishedContentProbe
{
    /**
     * The public content kinds that count as publish eligible.
     *
     * @var list<string>
     */
    public const POST_TYPES = ['post', 'page'];

    /**
     * The structural identity of the two factory items wp_install_defaults()
     * publishes on a fresh install: the first post (auto-increment ID 1) and
     * the first page (auto-increment ID 2), in that creation order. WordPress
     * post IDs are never reused, so while these rows exist they are the
     * factory content — in every locale.
     */
    public const FACTORY_POST_ID = 1;
    public const FACTORY_PAGE_ID = 2;

    public function hasEligibleContent(): bool
    {
        $posts = get_posts([
            'post_type' => self::POST_TYPES,
            'post_status' => 'publish',
        ]);

        foreach ($posts as $post) {
            // The two factory items a fresh install always ships with,
            // recognized by their structural identity (the first post and
            // page IDs) rather than by title, so a genuine user item titled
            // like a factory default is never filtered out.
            if ($this->isFactoryContent($post)) {
                continue;
            }

            $title = $this->extractTitle($post);
            if ($title !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a get_posts() row is one of the two factory items
     * wp_install_defaults() creates first on a fresh install (the first
     * post, ID 1, and the first page, ID 2). WordPress post IDs are
     * auto-increment and never reused, so these IDs identify the factory
     * content structurally — in every locale — without title comparison.
     *
     * @param object|array<string, mixed>|string $row
     */
    private function isFactoryContent(object|array|string $row): bool
    {
        if (is_object($row)) {
            $id = $row->ID ?? null;
            $type = $row->post_type ?? null;
        } elseif (is_array($row)) {
            $id = $row['ID'] ?? null;
            $type = $row['post_type'] ?? null;
        } else {
            return false;
        }

        if ($id === null || $type === null) {
            return false;
        }

        return ($type === 'post' && (int) $id === self::FACTORY_POST_ID)
            || ($type === 'page' && (int) $id === self::FACTORY_PAGE_ID);
    }

    /**
     * Extract the post title from a get_posts() row. WordPress returns WP_Post
     * objects (with a `post_title` property) or associative arrays; it does NOT
     * return bare title strings even when `fields => 'id=>title'` is requested.
     *
     * @param object|array<string, mixed>|string $row
     */
    private function extractTitle(object|array|string $row): ?string
    {
        if (is_object($row)) {
            return property_exists($row, 'post_title') ? (string) $row->post_title : null;
        }

        if (is_array($row)) {
            return isset($row['post_title']) ? (string) $row['post_title'] : null;
        }

        // Fallback: a bare string is already a title.
        return $row;
    }
}
