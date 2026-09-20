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
    }

    public function testReportsTrueWhenEligiblePublishableContentExists(): void
    {
        $GLOBALS['lumeweb_cast_has_publishable_content'] = true;

        self::assertTrue((new WordPressPublishedContentProbe())->hasEligibleContent());
    }

    public function testReportsFalseWhenOnlyNonPublicContentExists(): void
    {
        self::assertFalse((new WordPressPublishedContentProbe())->hasEligibleContent());
    }

    public function testQueriesAtMostOnePublishedPostOrPage(): void
    {
        $GLOBALS['lumeweb_cast_has_publishable_content'] = true;

        (new WordPressPublishedContentProbe())->hasEligibleContent();

        $args = $GLOBALS['lumeweb_cast_get_posts_args'];
        self::assertSame(['post', 'page'], $args['post_type']);
        self::assertSame('publish', $args['post_status']);
        self::assertSame(1, $args['numberposts']);
        self::assertSame('ids', $args['fields']);
    }

    public function testProbeNeverRunsAHeavyFullSiteExport(): void
    {
        (new WordPressPublishedContentProbe())->hasEligibleContent();

        // numberposts = 1 proves a bounded existence probe, not a full scan.
        self::assertSame(1, $GLOBALS['lumeweb_cast_get_posts_args']['numberposts'] ?? null);
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
