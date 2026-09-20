<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

/**
 * CSS rewriter.
 *
 * Rewrites url() and @import (url() and bare quoted forms) against the CSS
 * file URL carried in the context — never the page URL. data: URIs are left
 * whole, including SVG payloads that contain rgb(...), which must not end the
 * url() early: quoted values scan to the closing quote, bare values scan with
 * balanced parentheses. Comments and CSS strings are skipped so url(...)
 * spellings inside them are never rewritten. Original quoting is preserved and
 * Elementor numeric-entity icon content (content: "&#61710;") is converted to
 * a CSS escape ({@code "\f10e"}), because browsers do not decode HTML entities
 * in CSS.
 *
 * Dev-tool source pragmas are stripped before any URL logic: a sourceURL
 * comment (block form, plus the //# and //@ line forms some generators inject)
 * labels the generating file and is meaningless in a published site, and when
 * it carries an absolute http(s) URL it would leak the WordPress origin host.
 * A sourceMappingURL comment is only meaningful while its map sits next to the
 * sheet, and this export mirrors no source maps, so an absolute-URL reference
 * is dropped for the same origin-leak reason while a relative reference is left
 * untouched. Ordinary comments survive byte-for-byte.
 */
final class CssRewriter
{
    /** Matches a dev-tool line pragma, e.g. //# sourceURL=... or //@ sourceURL */
    private const LINE_PRAGMA_PATTERN = '~^//[ \t]*[#@][ \t]*(sourceURL|sourceMappingURL)[ \t]*=[ \t]*([^\r\n]*)~i';

    public function __construct(
        private readonly UrlConverter $urlConverter = new UrlConverter(),
    ) {
    }

    public function rewrite(string $css, RewriteContext $context): string
    {
        $css = $this->rewriteUrlsAndImports($css, $context);

        return $this->convertElementorIconEntities($css);
    }

    private function rewriteUrlsAndImports(string $css, RewriteContext $context): string
    {
        $out = '';
        $i = 0;
        $len = strlen($css);

        while ($i < $len) {
            $rest = substr($css, $i);

            if (str_starts_with($rest, '/*')) {
                $chunk = $this->consumeComment($css, $i);
                $out .= $this->stripDevToolPragmaComment($chunk);
                $i += strlen($chunk);
                continue;
            }

            // Invalid CSS, but minifiers/generators occasionally end a file
            // with a //# sourceURL= line: consume it as one pragma unit and
            // drop it. Anything else starting with '/' is ordinary CSS output.
            if (preg_match(self::LINE_PRAGMA_PATTERN, $rest, $m) === 1) {
                $out .= $this->stripDevToolPragmaLine($m[0]);
                $i += strlen($m[0]);
                continue;
            }

            $char = $css[$i];
            if ($char === '"' || $char === "'") {
                $end = $this->stringEnd($css, $i);
                $out .= substr($css, $i, $end - $i + 1);
                $i = $end + 1;
                continue;
            }

            if (strncasecmp($rest, 'url(', 4) === 0) {
                [$value, $quote, $endPos] = $this->parseUrlValue($css, $i + 4);
                $conversion = $this->convertUrl($value, $context);
                $presented = $this->present($value, $conversion, $context);
                $out .= 'url(' . ($quote !== null ? $quote . $presented . $quote : $presented) . ')';
                $i = $endPos;
                continue;
            }

            if (strncasecmp($rest, '@import', 7) === 0) {
                [$chunk, $endPos] = $this->rewriteImport($css, $i, $context);
                $out .= $chunk;
                $i = $endPos;
                continue;
            }

            $out .= $char;
            ++$i;
        }

        return $out;
    }

    /**
     * Rewrites an @import starting at $i. Returns the replacement chunk and the
     * absolute position the scanner must resume at (after the whole statement),
     * so consumed length never depends on how much shorter the rewrite is.
     *
     * @return array{0: string, 1: int} [chunk, resume position]
     */
    private function rewriteImport(string $css, int $i, RewriteContext $context): array
    {
        $len = strlen($css);
        $j = $i + 7;
        $prefixEnd = $j;
        while ($j < $len && ctype_space($css[$j])) {
            ++$j;
        }

        if (strncasecmp(substr($css, $j, 4), 'url(', 4) === 0) {
            // The url() part is handled by the main url() branch next; only the
            // '@import ' prefix is emitted here and scanning resumes at 'url('.
            return [substr($css, $i, $j - $i), $j];
        }

        if ($j < $len && ($css[$j] === '"' || $css[$j] === "'")) {
            $quote = $css[$j];
            $end = $this->stringEnd($css, $j);
            $value = substr($css, $j + 1, $end - $j - 1);
            $conversion = $this->convertUrl($value, $context);
            $presented = $this->present($value, $conversion, $context);

            return [substr($css, $i, $j - $i) . $quote . $presented . $quote, $end + 1];
        }

        // @import with an unsupported body: take one character so we never
        // spin; the rest is processed normally.
        return [$css[$i], $i + 1];
    }

    /**
     * @return array{0: string, 1: string|null, 2: int} [value, quote char or
     *                                                   null, absolute position after the closing paren]
     */
    private function parseUrlValue(string $css, int $start): array
    {
        $len = strlen($css);
        $j = $start;
        while ($j < $len && ctype_space($css[$j])) {
            ++$j;
        }

        if ($j < $len && ($css[$j] === '"' || $css[$j] === "'")) {
            $quote = $css[$j];
            $end = $this->stringEnd($css, $j);
            $value = substr($css, $j + 1, $end - $j - 1);

            $k = $end + 1;
            while ($k < $len && ctype_space($css[$k])) {
                ++$k;
            }

            $endPos = ($k < $len && $css[$k] === ')') ? $k + 1 : $end + 1;

            return [$value, $quote, $endPos];
        }

        // Termination comes from the inner break when the depth returns to 0
        // (the matching close paren), so the loop condition only bounds $k.
        $depth = 1;
        $k = $j;
        while ($k < $len) {
            if ($css[$k] === '(') {
                ++$depth;
            } elseif ($css[$k] === ')') {
                --$depth;
            }
            if ($depth === 0) {
                break;
            }
            ++$k;
        }

        $value = trim(substr($css, $j, $k - $j));
        $endPos = $k < $len ? $k + 1 : $k;

        return [$value, null, $endPos];
    }

    private function stringEnd(string $css, int $quotePos): int
    {
        $quote = $css[$quotePos];
        $len = strlen($css);
        $i = $quotePos + 1;
        while ($i < $len) {
            if ($css[$i] === '\\') {
                $i += 2;
                continue;
            }
            if ($css[$i] === $quote) {
                return $i;
            }
            ++$i;
        }

        return $len - 1;
    }

    private function consumeComment(string $css, int $start): string
    {
        $end = strpos($css, '*/', $start + 2);
        $endPos = $end === false ? strlen($css) : $end + 2;

        return substr($css, $start, $endPos - $start);
    }

    /**
     * Returns '' when the consumed comment is a dev-tool source pragma that
     * must never be published, and the comment unchanged otherwise. Only a
     * comment whose entire body is the pragma is dropped, so a normal comment
     * that merely mentions sourceURL survives byte-for-byte.
     */
    private function stripDevToolPragmaComment(string $comment): string
    {
        if (
            preg_match(
                '~^/\*\s*[#@]\s*(sourceURL|sourceMappingURL)\s*=\s*([^*]*?)\s*\*/\s*$~is',
                $comment,
                $m
            ) === 1
            && $this->shouldDropPragma($m[1], $m[2])
        ) {
            return '';
        }

        return $comment;
    }

    /**
     * Same decision for the //# and //@ line-pragma forms the block-comment
     * scanner above cannot see.
     */
    private function stripDevToolPragmaLine(string $line): string
    {
        if (
            preg_match(self::LINE_PRAGMA_PATTERN, $line, $m) === 1
            && $this->shouldDropPragma($m[1], $m[2])
        ) {
            return '';
        }

        return $line;
    }

    /**
     * sourceURL is always a dev label and is dropped in every spelling. A
     * sourceMappingURL only leaks the origin when it carries an absolute
     * http(s) URL, because this export mirrors no source maps; a relative (or
     * protocol-relative) map reference stays.
     */
    private function shouldDropPragma(string $kind, string $value): bool
    {
        return strcasecmp($kind, 'sourceURL') === 0 || $this->isAbsoluteHttp($value);
    }

    private function isAbsoluteHttp(string $value): bool
    {
        return preg_match('~^https?://~i', trim($value)) === 1;
    }

    private function convertUrl(string $value, RewriteContext $context): UrlConversion
    {
        if ($value === '' || strncasecmp(ltrim($value), 'data:', 5) === 0) {
            return UrlConversion::leftAsIs($value);
        }

        return $this->urlConverter->convert($value, $context->document(), $context->origin());
    }

    private function present(string $original, UrlConversion $conversion, RewriteContext $context): string
    {
        if ($conversion->unchanged()) {
            return $original;
        }

        if ($conversion->queued() !== null) {
            $context->queue()->queue($conversion->queued());
        }

        return $conversion->rewritten();
    }

    private function convertElementorIconEntities(string $css): string
    {
        return preg_replace_callback(
            '/(content\s*:\s*)((["\'])[^"\']*\3)/i',
            static function (array $m): string {
                $quote = $m[3];
                $inner = substr($m[2], 1, -1);
                $inner = preg_replace_callback(
                    '/&#(\d+);/',
                    static fn (array $n): string => '\\' . strtolower(dechex((int) $n[1])),
                    $inner
                );

                return $m[1] . $quote . $inner . $quote;
            },
            $css
        ) ?? $css;
    }
}
