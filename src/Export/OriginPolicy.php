<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Origin check for fetched URLs: probe and capture call assertAllowed() before
 * making a request; anything outside the exact WordPress origin throws.
 */
final class OriginPolicy
{
    public function isAllowed(Url $url, Origin $origin): bool
    {
        return $origin->matches($url);
    }

    public function assertAllowed(Url $url, Origin $origin): void
    {
        if (!$origin->matches($url)) {
            throw new OffOriginUrl((string) $url, $origin);
        }
    }
}
