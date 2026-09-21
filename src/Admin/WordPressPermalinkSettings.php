<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * The production {@see PermalinkSettings}: the live WordPress
 * `permalink_structure` option plus a persisted one-time flush flag.
 *
 * The flush flag (`cast_permalink_structure_flushed`) records that the hard
 * rewrite-rule flush already ran after an enforcement, so the guard never
 * hard-flushes on every administrative request — only on the transition that
 * actually needs it.
 */
final class WordPressPermalinkSettings implements PermalinkSettings
{
    private const OPTION_STRUCTURE = 'permalink_structure';
    private const FLUSH_FLAG_OPTION = 'cast_permalink_structure_flushed';

    public function structure(): string
    {
        return (string) get_option(self::OPTION_STRUCTURE, '');
    }

    public function setStructure(string $structure): void
    {
        update_option(self::OPTION_STRUCTURE, $structure, false);
    }

    public function hasHardFlushed(): bool
    {
        return (bool) get_option(self::FLUSH_FLAG_OPTION, false);
    }

    public function markHardFlushed(): void
    {
        update_option(self::FLUSH_FLAG_OPTION, true, false);
    }

    public function flushRewriteRules(): void
    {
        flush_rewrite_rules();
    }
}
