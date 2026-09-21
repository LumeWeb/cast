<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\WordPressDiscoverEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The WordPress {@see DiscoverEnvironment} adapter: reads the finite static
 * seeders, the detected sitemap candidates and the published-content keyset
 * from the real home_url()/get_option()/is_plugin_active()/get_post_types()/
 * legacy wpdb/permalinks surfaces, matching the pure {@see \LumeWeb\Cast\Export\DiscoverStage}
 * producers without loading WordPress directly.
 */
final class WordPressDiscoverEnvironmentTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_home_url'] = 'https://blog.example.test/';
        unset(
            $GLOBALS['lumeweb_cast_options']['show_on_front'],
            $GLOBALS['lumeweb_cast_options']['page_on_front'],
            $GLOBALS['lumeweb_cast_options']['page_for_posts'],
        );
        $GLOBALS['lumeweb_cast_permalinks'] = [];
        $GLOBALS['lumeweb_cast_active_plugins'] = [];
        $GLOBALS['lumeweb_cast_public_post_types'] = [];
        $GLOBALS['lumeweb_cast_wpdb_results'] = [];
        $GLOBALS['lumeweb_cast_wpdb_queries'] = [];
    }

    public function testStaticSeedsAreHomeRobotsAndTheKnownRootFiles(): void
    {
        self::assertSame(
            [
                'https://blog.example.test/',
                'https://blog.example.test/robots.txt',
                'https://blog.example.test/llms.txt',
                'https://blog.example.test/_redirects',
                'https://blog.example.test/_headers',
                'https://blog.example.test/favicon.ico',
            ],
            (new WordPressDiscoverEnvironment())->staticSeeds(),
        );
    }

    public function testStaticSeedsIncludeTheStaticFrontPageBetweenHomeAndRobots(): void
    {
        $GLOBALS['lumeweb_cast_options']['show_on_front'] = 'page';
        $GLOBALS['lumeweb_cast_options']['page_on_front'] = 7;
        $GLOBALS['lumeweb_cast_permalinks'][7] = 'https://blog.example.test/welcome/';
        $GLOBALS['lumeweb_cast_options']['page_for_posts'] = 11;
        $GLOBALS['lumeweb_cast_permalinks'][11] = 'https://blog.example.test/updates/';

        self::assertSame(
            [
                'https://blog.example.test/',
                'https://blog.example.test/welcome/',
                'https://blog.example.test/updates/',
                'https://blog.example.test/robots.txt',
                'https://blog.example.test/llms.txt',
                'https://blog.example.test/_redirects',
                'https://blog.example.test/_headers',
                'https://blog.example.test/favicon.ico',
            ],
            (new WordPressDiscoverEnvironment())->staticSeeds(),
        );
    }

    public function testStaticSeedsSkipPagesWhenNotUsingAStaticFrontPage(): void
    {
        $GLOBALS['lumeweb_cast_options']['show_on_front'] = 'posts';
        $GLOBALS['lumeweb_cast_options']['page_on_front'] = 7;
        $GLOBALS['lumeweb_cast_options']['page_for_posts'] = 11;
        $GLOBALS['lumeweb_cast_permalinks'][7] = 'https://blog.example.test/welcome/';
        $GLOBALS['lumeweb_cast_permalinks'][11] = 'https://blog.example.test/updates/';

        self::assertSame(
            [
                'https://blog.example.test/',
                'https://blog.example.test/robots.txt',
                'https://blog.example.test/llms.txt',
                'https://blog.example.test/_redirects',
                'https://blog.example.test/_headers',
                'https://blog.example.test/favicon.ico',
            ],
            (new WordPressDiscoverEnvironment())->staticSeeds(),
        );
    }

    public function testStaticSeedsSkipTheStaticFrontPageWhenItsIdIsZero(): void
    {
        $GLOBALS['lumeweb_cast_options']['show_on_front'] = 'page';
        $GLOBALS['lumeweb_cast_options']['page_on_front'] = 0;
        $GLOBALS['lumeweb_cast_options']['page_for_posts'] = 0;

        $seeds = (new WordPressDiscoverEnvironment())->staticSeeds();

        self::assertSame('https://blog.example.test/', $seeds[0]);
        self::assertSame('https://blog.example.test/robots.txt', $seeds[1]);
        self::assertCount(6, $seeds);
    }

    public function testSitemapCandidatesDefaultToTheCoreWpSitemapWhenNoSeoPluginIsActive(): void
    {
        self::assertSame(
            ['https://blog.example.test/wp-sitemap.xml'],
            (new WordPressDiscoverEnvironment())->sitemapCandidates(),
        );
    }

    public function testSitemapCandidatesDetectYoastFirst(): void
    {
        $GLOBALS['lumeweb_cast_active_plugins'] = [
            'wordpress-seo/wp-seo.php',
            'seo-by-rank-math/rank-math.php',
            'wp-seopress/seopress.php',
            'all-in-one-seo-pack/all_in_one_seo_pack.php',
        ];

        self::assertSame(
            ['https://blog.example.test/sitemap_index.xml'],
            (new WordPressDiscoverEnvironment())->sitemapCandidates(),
        );
    }

    public function testSitemapCandidatesDetectRankMathWhenYoastIsMissing(): void
    {
        $GLOBALS['lumeweb_cast_active_plugins'] = ['seo-by-rank-math/rank-math.php'];

        self::assertSame(
            ['https://blog.example.test/sitemap_index.xml'],
            (new WordPressDiscoverEnvironment())->sitemapCandidates(),
        );
    }

    public function testSitemapCandidatesDetectSeopressWhenEarlierSeoPluginsAreMissing(): void
    {
        $GLOBALS['lumeweb_cast_active_plugins'] = ['wp-seopress/seopress.php'];

        self::assertSame(
            ['https://blog.example.test/sitemaps.xml'],
            (new WordPressDiscoverEnvironment())->sitemapCandidates(),
        );
    }

    public function testSitemapCandidatesDetectAioseoLastAmongTheSeoPlugins(): void
    {
        $GLOBALS['lumeweb_cast_active_plugins'] = ['all-in-one-seo-pack/all_in_one_seo_pack.php'];

        self::assertSame(
            ['https://blog.example.test/sitemap.xml'],
            (new WordPressDiscoverEnvironment())->sitemapCandidates(),
        );
    }

    public function testPublishedPageReturnsIdsAndPermalinksForTheKeyedPage(): void
    {
        $GLOBALS['lumeweb_cast_wpdb_results'] = [
            (object) ['ID' => 11],
            (object) ['ID' => 12],
        ];
        $GLOBALS['lumeweb_cast_permalinks'][11] = 'https://blog.example.test/welcome/';
        $GLOBALS['lumeweb_cast_permalinks'][12] = 'https://blog.example.test/contact/';

        $page = (new WordPressDiscoverEnvironment())->publishedPage(afterId: 10, limit: 50);

        self::assertSame([11, 12], $page->ids);
        self::assertSame(
            ['https://blog.example.test/welcome/', 'https://blog.example.test/contact/'],
            $page->urls,
        );
    }

    public function testPublishedPageQueriesPublishedPostsPagesAndPublicCptsAfterTheCursor(): void
    {
        $GLOBALS['lumeweb_cast_public_post_types'] = ['product'];
        $GLOBALS['lumeweb_cast_wpdb_results'] = [];

        (new WordPressDiscoverEnvironment())->publishedPage(afterId: 10, limit: 50);

        self::assertCount(1, $GLOBALS['lumeweb_cast_wpdb_queries']);
        $sql = $GLOBALS['lumeweb_cast_wpdb_queries'][0];
        self::assertStringContainsString('wptests_posts', $sql);
        self::assertStringContainsString("post_status = 'publish'", $sql);
        self::assertStringContainsString("post_type IN ('post', 'page', 'product')", $sql);
        self::assertStringContainsString('ID > 10', $sql);
        self::assertStringContainsString('ORDER BY ID ASC', $sql);
        self::assertStringContainsString('LIMIT 50', $sql);
    }

    public function testPublishedPageIsEmptyWhenNoRowsArePublishedAfterTheCursor(): void
    {
        $GLOBALS['lumeweb_cast_wpdb_results'] = [];

        $page = (new WordPressDiscoverEnvironment())->publishedPage(afterId: 10, limit: 50);

        self::assertSame([], $page->ids);
        self::assertSame([], $page->urls);
    }

    public function testPublishedPageExcludesAttachmentsFromThePublicContentTypes(): void
    {
        $GLOBALS['lumeweb_cast_public_post_types'] = ['attachment', 'product'];
        $GLOBALS['lumeweb_cast_wpdb_results'] = [];

        (new WordPressDiscoverEnvironment())->publishedPage(afterId: 5, limit: 50);

        $sql = $GLOBALS['lumeweb_cast_wpdb_queries'][0];
        self::assertStringContainsString("post_type IN ('post', 'page', 'product')", $sql);
        self::assertStringNotContainsString('attachment', $sql);
    }
}
