<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

/**
 * HTML pipeline for the offline-zip destination.
 *
 * A single-pass, regex-free-at-tag-level scanner rewrites URL attributes in a
 * small tag/attribute map (href/src/srcset/imagesrcset/poster/action/formaction
 * /data + data-* including Elementor JSON), routes style= and <style> content
 * through the CSS rewriter, srcset/imagesrcset/data-*-srcset through the
 * comma-aware srcset splitter, Elementor/JSON data-* through the JSON rewriter,
 * and script src + inline script content through the JS rewriter — every one of
 * them resolved against the PAGE document URL through the same
 * UrlConverter/OriginPolicy check, so HTML and JS produce the exact same './'
 * offline shape (html-js-same-mode). Comments, raw-content elements
 * (script/style/textarea/xmp) and entity-encoded markup are preserved
 * byte-for-byte; external URLs are left unchanged and never queued (the
 * leftover-origin warning is a separate last pass). The head-strip runs as the
 * final step on the rewritten copy only.
 *
 * Practical minimal boundary: attribute values containing an HTML entity ('&')
 * are left untouched rather than risking a broken entity-encoded query, and
 * <meta http-equiv="refresh"> redirect URLs are not rewritten. Script/comment
 * preservation is exact because only recognized attribute value spans are ever
 * replaced. A naive DOMDocument is deliberately avoided because it mangles
 * script content and unclosed HTML5 section tags.
 */
final class HtmlRewriter
{
    /**
     * Single-URL attributes converted against the page document. 'content' is
     * gated behind a URL heuristic below (meta descriptions must not be parsed
     * as URLs).
     */
    private const URL_ATTRIBUTES = [
        'href',
        'src',
        'poster',
        'action',
        'formaction',
        'data',
        'xlink:href',
    ];

    /** @var list<string> */
    private const RAW_CONTENT_TAGS = ['script', 'style', 'textarea', 'xmp'];

    public function __construct(
        private readonly UrlConverter $urlConverter = new UrlConverter(),
        private readonly SrcsetSplitter $srcsetSplitter = new SrcsetSplitter(),
        private readonly CssRewriter $cssRewriter = new CssRewriter(),
        private readonly JsRewriter $jsRewriter = new JsRewriter(),
        private readonly JsonRewriter $jsonRewriter = new JsonRewriter(),
        private readonly HeadStripper $headStripper = new HeadStripper(),
    ) {
    }

    public function rewrite(string $html, RewriteContext $context): string
    {
        $rewritten = $this->scan($html, $context);

        return $this->headStripper->strip($rewritten, $context);
    }

    private function scan(string $html, RewriteContext $context): string
    {
        $out = '';
        $len = strlen($html);
        $i = 0;

        while ($i < $len) {
            $lt = strpos($html, '<', $i);
            if ($lt === false) {
                $out .= substr($html, $i);
                break;
            }

            $out .= substr($html, $i, $lt - $i);
            $i = $lt;

            // HTML comment — preserved byte-for-byte, never rewritten.
            if (substr($html, $i, 4) === '<!--') {
                $close = strpos($html, '-->', $i + 4);
                $end = $close === false ? $len : $close + 3;
                $out .= substr($html, $i, $end - $i);
                $i = $end;
                continue;
            }

            // Only a real element open tag is processed; '<', '</', '<?', '<!'
            // and stray '<' fall through as literal text one char at a time.
            if (preg_match('/^<([a-zA-Z][a-zA-Z0-9]*)(?:\s|\/|>)/', substr($html, $i), $m) !== 1) {
                $out .= $html[$i];
                ++$i;
                continue;
            }

            $tagName = strtolower($m[1]);
            $tagEnd = $this->tagEnd($html, $i);
            $openTag = substr($html, $i, $tagEnd - $i + 1);

            $out .= $this->rewriteTag($openTag, $context);

            $contentStart = $tagEnd + 1;

            if (!\in_array($tagName, self::RAW_CONTENT_TAGS, true)) {
                $i = $contentStart;
                continue;
            }

            // Raw-content element: content is not parsed as HTML. Rewrite only
            // the recognised container kinds and leave textarea/xmp/lone script
            // bodies byte-for-byte intact.
            $closePattern = '/<\/' . $tagName . '\s*>/i';
            if (preg_match($closePattern, $html, $cm, PREG_OFFSET_CAPTURE, $contentStart) === 1) {
                $closeStart = $cm[0][1];
                $closeTag = $cm[0][0];
                $content = substr($html, $contentStart, $closeStart - $contentStart);

                $rewrittenContent = match ($tagName) {
                    'style' => $this->cssRewriter->rewrite($content, $context),
                    'script' => $this->jsRewriter->rewrite($content, $context),
                    default => $content,
                };

                $out .= $rewrittenContent . $closeTag;
                $i = $closeStart + strlen($closeTag);
                continue;
            }

            // Unclosed raw-content element: keep the rest untouched.
            $out .= substr($html, $contentStart);
            $i = $len;
        }

        return $out;
    }

    /**
     * Rewrite the URL/JSON/style attribute values inside one open tag, keeping
     * every other character (quoting, whitespace, entities, non-URL attrs)
     * exactly as written.
     */
    private function rewriteTag(string $tag, RewriteContext $context): string
    {
        $pattern = '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s>\/]+)/';
        if (preg_match_all($pattern, $tag, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return $tag;
        }

        /** @var array<int, array{0: int, 1: string}> start => [len, replacement] */
        $updates = [];

        foreach ($matches as $group) {
            $name = strtolower($group[1][0]);
            $valueText = $group[3][0];
            $valueOffset = $group[3][1];

            $inner = $valueText;
            $innerOffset = $valueOffset;
            if (($valueText[0] === '"' || $valueText[0] === "'") && strlen($valueText) >= 2) {
                $inner = substr($valueText, 1, -1);
                $innerOffset = $valueOffset + 1;
            }

            $newInner = null;

            if ($name === 'style') {
                $newInner = $this->cssRewriter->rewrite($inner, $context);
            } elseif (str_ends_with($name, 'srcset')) {
                $newInner = $this->srcsetSplitter->rewrite(
                    $inner,
                    fn (string $url): string => $this->convertUrl($url, $context)
                );
            } elseif ($name === 'content') {
                if ($this->looksLikeUrl($inner)) {
                    $newInner = $this->convertUrl($inner, $context);
                }
            } elseif (\in_array($name, self::URL_ATTRIBUTES, true)) {
                $newInner = $this->convertUrl($inner, $context);
            } elseif (str_starts_with($name, 'data-')) {
                $trimmed = ltrim($inner);
                if (
                    $trimmed !== ''
                    && ($trimmed[0] === '{' || $trimmed[0] === '[')
                    && json_decode($inner) !== null
                ) {
                    $newInner = $this->jsonRewriter->rewrite($inner, $context);
                } elseif ($this->looksLikeUrl($inner)) {
                    $newInner = $this->convertUrl($inner, $context);
                }
            }

            if ($newInner !== null && $newInner !== $inner) {
                $updates[$innerOffset] = [strlen($inner), $newInner];
            }
        }

        if ($updates === []) {
            return $tag;
        }

        krsort($updates);
        $out = $tag;
        foreach ($updates as $start => [$length, $replacement]) {
            $out = substr($out, 0, $start) . $replacement . substr($out, $start + $length);
        }

        return $out;
    }

    /**
     * A value is treated as a URL reference when it is an absolute/protocol-
     * relative web URL or a root-relative path with at least one '/' (so plain
     * meta descriptions and short data attributes never get parsed).
     */
    private function looksLikeUrl(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return preg_match('{^(?:[a-z][a-z0-9+.\-]*:)?//}i', $value) === 1
            || (str_starts_with($value, '/') && str_contains(substr($value, 1), '/'));
    }

    /**
     * Resolve one raw URL reference through the shared check: queue in-origin
     * items, rewrite to the './' offline path, leave external/hash/non-web
     * references unchanged. Values containing an HTML entity are skipped so an
     * entity-encoded query can never be broken or double-rewritten.
     */
    private function convertUrl(string $value, RewriteContext $context): string
    {
        if (str_contains($value, '&')) {
            return $value;
        }

        $conversion = $this->urlConverter->convert($value, $context->document(), $context->origin());
        if ($conversion->unchanged()) {
            return $value;
        }

        if ($conversion->queued() !== null) {
            $context->queue()->queue($conversion->queued());
        }

        return $conversion->rewritten();
    }

    /**
     * Index of the '>' that ends the tag starting at $start, skipping quoted
     * attribute values that legitimately contain '>'.
     */
    private function tagEnd(string $html, int $start): int
    {
        $len = strlen($html);
        $quote = null;

        for ($i = $start; $i < $len; ++$i) {
            $char = $html[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '>') {
                return $i;
            }
        }

        return $len - 1;
    }
}
