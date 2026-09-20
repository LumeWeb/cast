<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * WordPress facts the discovery stage needs, isolated behind an interface so the
 * pure {@see DiscoverStage} never loads WordPress. Probes and sitemap fetches
 * stay origin-only in the stage; this interface only supplies the WordPress-
 * aware producers — the finite static seeders, the published-content keyset,
 * and the detected sitemap candidates. A concrete WordPress adapter reads
 * these from home_url()/get_option()/get_posts()/is_plugin_active(); unit
 * tests inject a scripted fake instead.
 */
interface DiscoverEnvironment
{
    /**
     * Finite, absolute seed URLs: home, static front page when configured,
     * posts page when configured, robots.txt. and the well-known files
     * (llms.txt, _redirects, _headers, favicon.ico).
     *
     * @return list<string>
     */
    public function staticSeeds(): array;

    /**
     * Absolute sitemap document URLs detected from the installed SEO plugins
     * (Yoast, Rank Math, SEOPress, AIOSEO) and the core/default candidate.
     *
     * @return list<string>
     */
    public function sitemapCandidates(): array;

    /**
     * One keyset page of published content strictly after the given post ID —
     * `ID > $afterId ORDER BY ID LIMIT $limit`, never posts_per_page=-1.
     * The returned ids drive cursor advancement; the urls are the permalinks
     * to enqueue.
     */
    public function publishedPage(int $afterId, int $limit): PostIdPage;
}
