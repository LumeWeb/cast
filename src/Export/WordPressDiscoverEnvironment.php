<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Concrete {@see DiscoverEnvironment} that reads the finite discovery
 * producers from the real WordPress runtime surfaces: home_url(),
 * get_option(), get_permalink(), is_plugin_active(), get_post_types() and the
 * legacy `$wpdb` posts table. It reflects actual site state (static front
 * page, posts page, installed SEO plugins, published content) without ever
 * touching the network itself — the pure {@see DiscoverStage} owns all
 * fetching and enqueueing.
 */
final class WordPressDiscoverEnvironment implements DiscoverEnvironment
{
    private const PLUGIN_YOAST = 'wordpress-seo/wp-seo.php';
    private const PLUGIN_RANK_MATH = 'seo-by-rank-math/rank-math.php';
    private const PLUGIN_SEOPRESS = 'wp-seopress/seopress.php';
    private const PLUGIN_AIOSEO = 'all-in-one-seo-pack/all_in_one_seo_pack.php';

    /**
     * Sitemap document names per installed SEO plugin, in detection order
     * (Yoast → Rank Math → SEOPress → AIOSEO → core/default).
     */
    private const SITEMAP_YOAST = '/sitemap_index.xml';
    private const SITEMAP_RANK_MATH = '/sitemap_index.xml';
    private const SITEMAP_SEOPRESS = '/sitemaps.xml';
    private const SITEMAP_AIOSEO = '/sitemap.xml';
    private const SITEMAP_CORE = '/wp-sitemap.xml';

    /**
     * The finite absolute seed URLs: home, static front page when configured,
     * posts page when configured, plus the known root files the classifier's
     * Text/Asset kinds already understand (robots, llms, Netlify redirects and
     * headers, favicon).
     *
     * @return list<string>
     */
    public function staticSeeds(): array
    {
        $seeds = [home_url('/')];

        if ((string) get_option('show_on_front', 'posts') === 'page') {
            $frontPage = (int) get_option('page_on_front', 0);
            if ($frontPage > 0) {
                $permalink = get_permalink($frontPage);
                if (is_string($permalink) && $permalink !== '') {
                    $seeds[] = $permalink;
                }
            }

            // The posts page only exists when a static front page is in play;
            // with the default blog-on-home (`show_on_front = posts`) WordPress
            // ignores page_for_posts entirely.
            $postsPage = (int) get_option('page_for_posts', 0);
            if ($postsPage > 0) {
                $permalink = get_permalink($postsPage);
                if (is_string($permalink) && $permalink !== '') {
                    $seeds[] = $permalink;
                }
            }
        }

        $seeds[] = home_url('/robots.txt');
        foreach (['llms.txt', '_redirects', '_headers', 'favicon.ico'] as $file) {
            $seeds[] = home_url('/' . $file);
        }

        return $seeds;
    }

    /**
     * Absolute sitemap document URLs detected from the installed SEO plugins
     * (first active in detection order wins) and the core/default candidate.
     *
     * @return list<string>
     */
    public function sitemapCandidates(): array
    {
        if (is_plugin_active(self::PLUGIN_YOAST)) {
            return [$this->sitemap(self::SITEMAP_YOAST)];
        }
        if (is_plugin_active(self::PLUGIN_RANK_MATH)) {
            return [$this->sitemap(self::SITEMAP_RANK_MATH)];
        }
        if (is_plugin_active(self::PLUGIN_SEOPRESS)) {
            return [$this->sitemap(self::SITEMAP_SEOPRESS)];
        }
        if (is_plugin_active(self::PLUGIN_AIOSEO)) {
            return [$this->sitemap(self::SITEMAP_AIOSEO)];
        }

        return [$this->sitemap(self::SITEMAP_CORE)];
    }

    /**
     * One keyset page of published content strictly after the given post ID,
     * scoped to the post/page families and the site's own public content types
     * (media/library types excluded): `ID > $afterId ORDER BY ID LIMIT $limit`,
     * never posts_per_page=-1. The live `$wpdb->prefix` names the posts table
     * exactly like core, and the permalinks come from get_permalink().
     */
    public function publishedPage(int $afterId, int $limit): PostIdPage
    {
        $ids = [];
        $urls = [];

        $postTypes = ['post', 'page'];
        foreach (get_post_types(['public' => true]) as $type) {
            // The media library is not pageable content the crawler enqueues.
            if ($type === 'attachment' || in_array($type, $postTypes, true)) {
                continue;
            }
            $postTypes[] = $type;
        }

        $types = implode(', ', array_map(
            static fn (string $type): string => "'" . addslashes($type) . "'",
            $postTypes,
        ));

        $sql = 'SELECT ID FROM ' . $this->db()->prefix . 'posts'
            . " WHERE post_status = 'publish'"
            . " AND post_type IN ({$types})"
            . ' AND ID > ' . (int) $afterId
            . ' ORDER BY ID ASC'
            . ' LIMIT ' . (int) $limit;

        // wpdb returns null for an empty/errored result set; an empty page is a
        // valid (finished) keyset, never an exception.
        $rows = $this->db()->get_results($sql) ?? [];
        foreach ($rows as $row) {
            $id = (int) ($row->ID ?? 0);
            if ($id < 1) {
                continue;
            }
            $permalink = get_permalink($id);
            $ids[] = $id;
            $urls[] = is_string($permalink) ? $permalink : home_url('/?p=' . $id);
        }

        return new PostIdPage($ids, $urls);
    }

    private function sitemap(string $path): string
    {
        return home_url($path);
    }

    /**
     * The live `$wpdb` handle, narrowed to the surface Cast talks to.
     *
     * No native return type: the handle is the `$wpdb` global and must not be
     * enforced at runtime; the wpdb narrowing is purely a static-analysis
     * contract.
     *
     * @return \wpdb
     */
    private function db()
    {
        /** @var \wpdb $db */
        $db = $GLOBALS['wpdb'];

        return $db;
    }
}
