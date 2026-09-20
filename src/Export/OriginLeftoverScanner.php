<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Pre-pack last-pass scanner for origin URLs that survived rewriting: plain
 * absolute, protocol-relative, JSON-escaped, and percent-encoded forms of the
 * configured origin host. Purely observational — content is never mutated —
 * and feeds completed_with_warnings findings (or a strict-mode failure).
 */
final class OriginLeftoverScanner
{
    public function __construct(private readonly Origin $origin)
    {
    }

    public function hasLeftover(string $content): bool
    {
        return $this->count($content) > 0;
    }

    public function count(string $content): int
    {
        $host = preg_quote($this->origin->host(), '~');

        // Plain absolute (with optional port), JSON-escaped (\/
        // separators), protocol-relative (not after ':' so the '//' inside
        // https:// is not double counted), and percent-encoded forms.
        $pattern = '~(?i)' .
            'https?://' . $host . '(?::\d+)?|' .
            'https?:(?:\\\\?/){2}' . $host . '|' .
            '(?<![:%])//' . $host . '(?::\d+)?|' .
            'https?%3[aA](?:%2[fF]%2[fF]|//|\\\\?/\\\\?/)' . $host . '|' .
            '(?<![:%])%2[fF]%2[fF]' . $host .
            '~';

        $count = preg_match_all($pattern, $content);

        return $count === false ? 0 : $count;
    }
}
