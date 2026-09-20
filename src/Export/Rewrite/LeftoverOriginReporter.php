<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

/**
 * Encoded-last-pass boundary for offline mode.
 *
 * Offline depth is computed per-reference against the page file, so a global
 * host swap can never recompute '../'. When parse-and-replace has finished,
 * this reporter scans the rewritten document for origin URLs that still survive
 * — absolute, protocol-relative, and JSON-escaped forms — and records a
 * manifest warning instead of smashing the host inside strings. It never
 * mutates the content.
 */
final class LeftoverOriginReporter
{
    public function report(string $content, RewriteContext $context, WarningCollector $warnings): string
    {
        if (!$this->containsLeftoverOrigin($content, $context->origin()->host())) {
            return $content;
        }

        $warnings->record(
            WarningCollector::ORIGIN_LEFTOVER,
            sprintf(
                'Origin host "%s" still appears in rewritten content; offline depth cannot be recomputed for it, so the URL was left as-is.',
                $context->origin()->host()
            )
        );

        return $content;
    }

    private function containsLeftoverOrigin(string $content, string $host): bool
    {
        $quoted = preg_quote($host, '~');

        // Absolute (https://host, http://host) and JSON-escaped (https:\/\/host)
        // plus protocol-relative (//host) origin references.
        return preg_match(
            '~(?:(?:https?:(?://|\\\\/\\\\/))|(?<![a-zA-Z0-9+/])//)' . $quoted . '~i',
            $content
        ) === 1;
    }
}
