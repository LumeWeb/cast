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
 * positive). The returned titles are therefore filtered against those core
 * defaults (resolved through __() so a localized install is recognized too)
 * and only a genuine item beyond the defaults makes the site eligible.
 *
 * get_posts() and __() are called through the bootstrap shim in tests and
 * resolve to core in production, so no extra WordPress boundary object is
 * needed — this stays a pure existence probe.
 *
 * Important: WordPress get_posts() returns WP_Post objects (or arrays), NOT
 * an id=>title map, even when `fields => 'id=>title'` is requested. The
 * probe therefore extracts the `post_title` property from each returned row
 * rather than treating the rows as bare title strings.
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
     * The original-language titles of the WordPress core factory content that
     * wp_install_defaults() publishes on a fresh install. Kept as the source
     * strings so __() localizes them at probe time, exactly as core does when
     * it creates the content, so a non-English install's defaults are excluded
     * too.
     */
    private const DEFAULT_POST_TITLE = 'Hello world!';
    private const DEFAULT_PAGE_TITLE = 'Sample Page';

    public function hasEligibleContent(): bool
    {
        $posts = get_posts([
            'post_type' => self::POST_TYPES,
            'post_status' => 'publish',
        ]);

        // The localized factory titles a fresh install always ships with; these
        // never count as user content. The translation table is loaded on
        // admin screens where this probe runs, so __() matches core's output.
        $defaults = [
            __(self::DEFAULT_POST_TITLE),
            __(self::DEFAULT_PAGE_TITLE),
        ];

        foreach ($posts as $post) {
            $title = $this->extractTitle($post);
            if ($title !== null && !in_array($title, $defaults, true)) {
                return true;
            }
        }

        return false;
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
