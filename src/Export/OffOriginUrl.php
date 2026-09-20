<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Thrown when a candidate URL falls outside the exact WordPress origin, i.e.
 * the request must never be made (SSRF check).
 */
final class OffOriginUrl extends \RuntimeException
{
    public function __construct(
        string $url,
        Origin $origin,
    ) {
        parent::__construct(sprintf('URL %s is not inside the WordPress origin %s', $url, (string) $origin));
    }
}
