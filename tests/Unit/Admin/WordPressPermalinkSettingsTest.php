<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\WordPressPermalinkSettings;
use PHPUnit\Framework\TestCase;

/**
 * The production {@see \LumeWeb\Cast\Admin\PermalinkSettings}: the live
 * WordPress `permalink_structure` option plus the persisted one-time flush
 * flag. These tests pin the option contract — a missing/empty structure reads
 * back as plain, a written structure persists, the flush flag defaults to
 * false and marks true once — and that a hard flush records through the
 * bootstrap shim exactly once per call.
 */
final class WordPressPermalinkSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_options'] = [];
        $GLOBALS['lumeweb_cast_rewrite_flushes'] = 0;
    }

    public function testStructureDefaultsToEmptyPlain(): void
    {
        self::assertSame('', (new WordPressPermalinkSettings())->structure());
    }

    public function testSetStructurePersistsTheOption(): void
    {
        $settings = new WordPressPermalinkSettings();

        $settings->setStructure('/%year%/%monthnum%/%day%/%postname%/');

        self::assertSame('/%year%/%monthnum%/%day%/%postname%/', $settings->structure());
    }

    public function testFlushFlagDefaultsFalseAndMarksTrueOnce(): void
    {
        $settings = new WordPressPermalinkSettings();

        self::assertFalse($settings->hasHardFlushed());

        $settings->markHardFlushed();

        self::assertTrue($settings->hasHardFlushed());
    }

    public function testFlushRewriteRulesRecordsThroughTheShim(): void
    {
        $settings = new WordPressPermalinkSettings();

        $settings->flushRewriteRules();
        $settings->flushRewriteRules();

        self::assertSame(2, $GLOBALS['lumeweb_cast_rewrite_flushes']);
    }
}
