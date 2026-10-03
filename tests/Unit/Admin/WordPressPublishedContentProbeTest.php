<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\WordPressPublishedContentProbe;
use PHPUnit\Framework\TestCase;

final class WordPressPublishedContentProbeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_has_publishable_content'] = false;
        $GLOBALS['lumeweb_cast_get_posts_args'] = [];
        $GLOBALS['lumeweb_cast_get_posts_titles'] = null;
        $GLOBALS['lumeweb_cast_get_posts_types'] = [];
        $GLOBALS['lumeweb_cast_translations'] = [];
    }

    public function testReportsTrueWhenEligiblePublishableContentExists(): void
    {
        $GLOBALS['lumeweb_cast_has_publishable_content'] = true;

        self::assertTrue((new WordPressPublishedContentProbe())->hasEligibleContent());
    }

    public function testFactoryDefaultsAloneAreNotEligible(): void
    {
        // A freshly installed WordPress site's only published items are the
        // core factory post and page. They must NOT make the site publish-ready.
        $GLOBALS['lumeweb_cast_get_posts_titles'] = [
            1 => 'Hello world!',
            2 => 'Sample Page',
        ];
        $GLOBALS['lumeweb_cast_get_posts_types'] = [
            1 => 'post',
            2 => 'page',
        ];

        self::assertFalse((new WordPressPublishedContentProbe())->hasEligibleContent());
    }

    public function testUserContentAlongsideFactoryDefaultsIsEligible(): void
    {
        // One genuine post beside the two factory defaults is real content and
        // must make the site eligible — the filter drops only the defaults.
        $GLOBALS['lumeweb_cast_get_posts_titles'] = [
            1 => 'Hello world!',
            2 => 'Sample Page',
            3 => 'My real post',
        ];
        $GLOBALS['lumeweb_cast_get_posts_types'] = [
            1 => 'post',
            2 => 'page',
        ];

        self::assertTrue((new WordPressPublishedContentProbe())->hasEligibleContent());
    }

    public function testGenuineUserContentWithFactoryTitlesRemainsEligible(): void
    {
        // A genuine user post titled EXACTLY like a factory default is not the
        // factory content: it carries a later auto-increment ID. Identifying
        // the factory items structurally (post ID 1 / page ID 2) must keep
        // this real content eligible — title equality must not filter it out,
        // which would block the first publish on a fresh site.
        $GLOBALS['lumeweb_cast_get_posts_titles'] = [
            1 => 'Hello world!',
            2 => 'Sample Page',
            7 => 'Hello world!',
            8 => 'Sample Page',
        ];
        $GLOBALS['lumeweb_cast_get_posts_types'] = [
            1 => 'post',
            2 => 'page',
            7 => 'post',
            8 => 'page',
        ];

        self::assertTrue((new WordPressPublishedContentProbe())->hasEligibleContent());
    }

    public function testLocalizedFactoryDefaultsAloneAreNotEligible(): void
    {
        // A non-English install localizes the factory titles; the probe must
        // recognize the localized strings (via __()) and still exclude them.
        $GLOBALS['lumeweb_cast_translations'] = [
            'default|Hello world!' => 'Bonjour le monde !',
            'default|Sample Page' => "Page d'exemple",
        ];
        $GLOBALS['lumeweb_cast_get_posts_titles'] = [
            1 => 'Bonjour le monde !',
            2 => "Page d'exemple",
        ];
        $GLOBALS['lumeweb_cast_get_posts_types'] = [
            1 => 'post',
            2 => 'page',
        ];

        self::assertFalse((new WordPressPublishedContentProbe())->hasEligibleContent());
    }

    public function testReportsFalseWhenOnlyNonPublicContentExists(): void
    {
        self::assertFalse((new WordPressPublishedContentProbe())->hasEligibleContent());
    }

    public function testQueriesPublishedPostPageTitles(): void
    {
        $GLOBALS['lumeweb_cast_has_publishable_content'] = true;

        (new WordPressPublishedContentProbe())->hasEligibleContent();

        $args = $GLOBALS['lumeweb_cast_get_posts_args'];
        self::assertSame(['post', 'page'], $args['post_type']);
        self::assertSame('publish', $args['post_status']);
        // No `fields` param: WordPress returns WP_Post objects regardless,
        // and the probe extracts post_title from each row.
        self::assertArrayNotHasKey('fields', $args);
    }

    public function testProbeNeverRunsAHeavyFullSiteExport(): void
    {
        (new WordPressPublishedContentProbe())->hasEligibleContent();

        // The query is bounded by post_type + post_status only — never a
        // full-site export or unbounded scan.
        self::assertArrayNotHasKey('fields', $GLOBALS['lumeweb_cast_get_posts_args']);
        self::assertArrayNotHasKey('numberposts', $GLOBALS['lumeweb_cast_get_posts_args']);
    }

    public function testExcludedPostTypesAndStatusesAreNeverRequested(): void
    {
        (new WordPressPublishedContentProbe())->hasEligibleContent();

        $args = $GLOBALS['lumeweb_cast_get_posts_args'];
        // Revisions / autosaves / attachments are not in the post types, and
        // drafts never match the publish status.
        self::assertSame(['post', 'page'], $args['post_type']);
        self::assertSame('publish', $args['post_status']);
        self::assertNotContains('revision', $args['post_type']);
        self::assertNotContains('attachment', $args['post_type']);
    }
}
