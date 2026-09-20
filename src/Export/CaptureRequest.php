<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The minimal typed HTTP request capture issues. No cookies or credentials
 * exist in this pure module; a later WordPress adapter decides how to turn the
 * URL and headers into wp_remote_get().
 */
final class CaptureRequest
{
    /**
     * @param list<string> $headers
     */
    public function __construct(
        public readonly string $url,
        public readonly array $headers = [],
    ) {
    }
}
