<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Ghost guard used by capture and by the pre-pack root-index check: a
 * usable HTML document must be at least one KiB and must actually contain an
 * <html> tag. Mirrors the crawler rule that sub-1KiB or tagless bodies never
 * leave a real page behind.
 */
final class HtmlLike
{
    public const MIN_GHOST_BYTES = 1024;

    public static function isNonGhost(string $html): bool
    {
        return strlen($html) >= self::MIN_GHOST_BYTES
            && stripos($html, '<html') !== false;
    }
}
