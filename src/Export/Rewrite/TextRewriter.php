<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

/**
 * Text-file rewriter (robots.txt, _redirects, _headers, llms.txt).
 *
 * Only absolute and protocol-relative URL references are converted to offline
 * paths (relative to the text file's own location) and queued; a Sitemap:
 * label stays put. Root-relative rule paths such as Disallow: /wp-admin/ are
 * policy, not references, and are never rewritten.
 */
final class TextRewriter
{
    public function __construct(
        private readonly UrlConverter $urlConverter = new UrlConverter(),
    ) {
    }

    public function rewrite(string $text, RewriteContext $context): string
    {
        if (trim($text) === '') {
            return $text;
        }

        return preg_replace_callback(
            '{(?:(?:https?:)?//)[^\s"\'<>`]+}i',
            fn (array $m): string => $this->convertAndPresent($m[0], $context),
            $text
        ) ?? $text;
    }

    private function convertAndPresent(string $value, RewriteContext $context): string
    {
        $conversion = $this->urlConverter->convert($value, $context->document(), $context->origin());
        if ($conversion->unchanged()) {
            return $value;
        }

        if ($conversion->queued() !== null) {
            $context->queue()->queue($conversion->queued());
        }

        return $conversion->rewritten();
    }
}
