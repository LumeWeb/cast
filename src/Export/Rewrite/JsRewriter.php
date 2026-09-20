<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

/**
 * JS / JSON-in-script rewriter.
 *
 * HTML and JS MUST use the same destination mode, so a root-relative asset
 * inside a JS string converts to the exact same './' offline shape as an HTML
 * src attribute. A script that looks like JSON (trim starts with '{' or '[' and
 * json_decode succeeds — e.g. Elementor config, import maps) is delegated to
 * the JSON rewriter, not smashed by a host swap. Otherwise the script is
 * scanned with comment and string awareness: quoted string literals whose whole
 * content is a clean URL reference (scheme://, //host/path or /wp-content/...,
 * no whitespace) are resolved through the same UrlConverter/OriginPolicy check
 * and queued when in-origin; //# sourceMappingURL and //@ sourceURL directives
 * in line comments are converted; plain comments and block comments are left
 * byte-for-byte untouched so a bare '//' is never mistaken for a
 * protocol-relative URL.
 *
 * Practical minimal boundary: only quoted strings and the two source-map
 * directives are parsed. Template literals, regex literals and bare URL text in
 * executable code are intentionally not special-cased (those are syntax, not
 * references) and JSON-escaped ''\/\/host'' tokens are covered by the JSON
 * delegation path; entity decoding is left to the HTML pipeline. A /-prefixed
 * string literal that is not really a URL (e.g. a CSS selector string) is a
 * recognised false-positive of the same class Simply Static accepts and is
 * documented as a gap rather than silently ignored.
 */
final class JsRewriter
{
    public function __construct(
        private readonly UrlConverter $urlConverter = new UrlConverter(),
        private readonly JsonRewriter $jsonRewriter = new JsonRewriter(),
    ) {
    }

    public function rewrite(string $js, RewriteContext $context): string
    {
        $trimmed = ltrim($js);
        $looksLikeJson = str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[');

        if ($looksLikeJson && json_decode($js) !== null) {
            return $this->jsonRewriter->rewrite($js, $context);
        }

        return $this->scan($js, $context);
    }

    private function scan(string $js, RewriteContext $context): string
    {
        $out = '';
        $len = strlen($js);
        $i = 0;

        while ($i < $len) {
            $char = $js[$i];

            if ($char === '/' && $i + 1 < $len && $js[$i + 1] === '/') {
                [$chunk, $i] = $this->consumeLineComment($js, $i, $context);
                $out .= $chunk;
                continue;
            }

            if ($char === '/' && $i + 1 < $len && $js[$i + 1] === '*') {
                [$chunk, $i] = $this->consumeBlockComment($js, $i);
                $out .= $chunk;
                continue;
            }

            if ($char === '"' || $char === "'") {
                [$chunk, $i] = $this->consumeString($js, $i, $context);
                $out .= $chunk;
                continue;
            }

            $out .= $char;
            ++$i;
        }

        return $out;
    }

    /**
     * @param int $start index of the opening quote
     * @return array{0: string, 1: int} [output chunk, resume index]
     */
    private function consumeString(string $js, int $start, RewriteContext $context): array
    {
        $quote = $js[$start];
        $len = strlen($js);
        $i = $start + 1;

        while ($i < $len) {
            $char = $js[$i];

            if ($char === '\\') {
                $i += 2; // escaped char, including an escaped quote
                continue;
            }

            if ($char === $quote) {
                $content = substr($js, $start + 1, $i - $start - 1);

                if ($this->isUrlLikeString($content)) {
                    return [$quote . $this->reEscape($this->convert($content, $context), $quote) . $quote, $i + 1];
                }

                return [substr($js, $start, $i - $start + 1), $i + 1];
            }

            ++$i;
        }

        // Unterminated string literal — leave untouched from the opening quote on.
        return [substr($js, $start), $len];
    }

    /**
     * @return array{0: string, 1: int} [output chunk, resume index]
     */
    private function consumeLineComment(string $js, int $start, RewriteContext $context): array
    {
        $len = strlen($js);
        $eol = strpos($js, "\n", $start);
        $end = $eol === false ? $len : $eol;

        $comment = substr($js, $start, $end - $start);

        if (preg_match('~^(//\s*[#@]\s*(?:sourceMappingURL|sourceURL)\s*=\s*)(.*)$~', $comment, $m) === 1) {
            $chunk = $m[1] . $this->convert($m[2], $context);
        } else {
            $chunk = $comment;
        }

        if ($eol !== false) {
            $chunk .= "\n";
        }

        return [$chunk, $end + ($eol !== false ? 1 : 0)];
    }

    /**
     * @return array{0: string, 1: int} [output chunk, resume index]
     */
    private function consumeBlockComment(string $js, int $start): array
    {
        $close = strpos($js, '*/', $start + 2);
        $len = strlen($js);

        if ($close === false) {
            return [substr($js, $start), $len];
        }

        return [substr($js, $start, $close - $start + 2), $close + 2];
    }

    /**
     * A quoted JS string is a URL reference worth converting only when its whole
     * content is a clean web URL/path: an absolute http(s):// reference, a
     * protocol-relative //host/path or a root-relative /wp-content/... path.
     * Non-web schemes and plain text (including data: URIs) are left untouched.
     */
    private function isUrlLikeString(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if (preg_match('{^[a-z][a-z0-9+.\-]*://}i', $value) === 1) {
            return true;
        }

        if (str_starts_with($value, '//') && str_contains(substr($value, 2), '/')) {
            return true;
        }

        return str_starts_with($value, '/') && str_contains(substr($value, 1), '/');
    }

    /**
     * Guard: the offline './' path never contains quotes or backslashes, but if a
     * converted value ever did, keep the rebuilt string literal valid.
     */
    private function reEscape(string $value, string $quote): string
    {
        $value = str_replace('\\', '\\\\', $value);

        return str_replace($quote, '\\' . $quote, $value);
    }

    private function convert(string $value, RewriteContext $context): string
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
