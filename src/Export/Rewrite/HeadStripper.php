<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

/**
 * Copy-only head-strip after URL rewriting.
 *
 * The rewritten copy must not advertise WordPress: generator meta, RSD/WLW/
 * shortlink/REST links, oEmbed discovery alternates, emoji scripts, RSS
 * alternates and resource hints pointed at the origin are removed. Everything
 * the page actually needs survives — canonical, Open Graph, Twitter, robots,
 * description, hreflang, JSON-LD, prev/next, stylesheets, preloads and icons.
 * This runs on the exported HTML only; the live site is never mutated.
 */
final class HeadStripper
{
    public function strip(string $html, RewriteContext $context): string
    {
        if (!preg_match('/<head\b[^>]*>/i', $html, $hm, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $headOpenEnd = $hm[0][1] + strlen($hm[0][0]);

        if (!preg_match('/<\/head\s*>/i', $html, $cm, PREG_OFFSET_CAPTURE, $headOpenEnd)) {
            return $html;
        }

        $headEnd = $cm[0][1];
        $headInner = substr($html, $headOpenEnd, $headEnd - $headOpenEnd);
        $stripped = $this->stripInner($headInner, $context->origin()->host());

        return substr($html, 0, $headOpenEnd) . $stripped . substr($html, $headEnd);
    }

    private function stripInner(string $head, string $originHost): string
    {
        $out = '';
        $i = 0;
        $len = strlen($head);

        while ($i < $len) {
            if ($head[$i] !== '<') {
                $out .= $head[$i];
                ++$i;
                continue;
            }

            $rest = substr($head, $i);

            // <script> elements are removed as a whole unit (open tag + content).
            if (preg_match('/^<\s*script\b/i', $rest, $m)) {
                $tagEnd = $this->tagEnd($head, $i);
                $openTag = substr($head, $i, $tagEnd - $i + 1);

                if (preg_match('/<\/\s*script\s*>/i', substr($head, $tagEnd + 1), $cm, PREG_OFFSET_CAPTURE)) {
                    $elementEnd = $tagEnd + 1 + $cm[0][1] + strlen($cm[0][0]);
                    $element = substr($head, $i, $elementEnd - $i);
                    if ($this->shouldRemoveScript($openTag)) {
                        $i = $elementEnd;
                        continue;
                    }
                    $out .= $element;
                    $i = $elementEnd;
                    continue;
                }

                $out .= $openTag;
                $i = $tagEnd + 1;
                continue;
            }

            $tagEnd = $this->tagEnd($head, $i);
            $tag = substr($head, $i, $tagEnd - $i + 1);
            if ($this->shouldRemoveTag($tag, $originHost)) {
                $i += strlen($tag);
                continue;
            }

            $out .= $tag;
            $i += strlen($tag);
        }

        return $out;
    }

    private function shouldRemoveTag(string $tag, string $originHost): bool
    {
        if (preg_match('/^<\s*([a-zA-Z][a-zA-Z0-9-]*)/', $tag, $m) !== 1) {
            return false;
        }

        $tagName = strtolower($m[1]);
        $attrs = $this->attributes($tag);

        if ($tagName === 'meta') {
            return strtolower($attrs['name'] ?? '') === 'generator';
        }

        if ($tagName !== 'link') {
            return false;
        }

        $rel = strtolower($attrs['rel'] ?? '');
        $type = strtolower($attrs['type'] ?? '');
        $href = $attrs['href'] ?? '';

        // WordPress fingerprint links.
        if ($rel === 'edituri' || $rel === 'wlwmanifest' || $rel === 'shortlink') {
            return true;
        }

        // REST discovery: rel literally is the api.w.org URL.
        if (str_starts_with($rel, 'https://api.w.org')) {
            return true;
        }

        // oEmbed discovery alternates.
        if ($type === 'application/json+oembed' || $type === 'text/xml+oembed') {
            return true;
        }

        // RSS feed alternates are stripped in this pass (feeds not surfaced).
        if ($rel === 'alternate' && $type === 'application/rss+xml') {
            return true;
        }

        // Resource hints aimed at the origin host or empty should not survive;
        // hints to external hosts (fonts, CDNs) are kept.
        if (($rel === 'preconnect' || $rel === 'dns-prefetch') && $this->hrefTargetsOrigin($href, $originHost)) {
            return true;
        }

        return false;
    }

    private function shouldRemoveScript(string $openTag): bool
    {
        $attrs = $this->attributes($openTag);
        $src = strtolower($attrs['src'] ?? '');

        return str_contains($src, 'wp-emoji') || str_contains($src, 'wp-embed.min.js');
    }

    private function hrefTargetsOrigin(string $href, string $originHost): bool
    {
        $href = trim($href);
        if ($href === '') {
            return true;
        }

        $normalized = preg_replace('~^[a-zA-Z][a-zA-Z0-9+.-]*://~i', '', $href) ?? $href;
        $normalized = preg_replace('~^//~', '', $normalized) ?? $normalized;
        $normalized = rtrim($normalized, '/');

        return strcasecmp($normalized, $originHost) === 0;
    }

    /**
     * @return array<string, string> lowercased attribute name => raw value
     */
    private function attributes(string $tag): array
    {
        $attrs = [];
        if (preg_match_all('/(\b[a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>\/]+)/', $tag, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $raw = $match[2];
                $attrs[strtolower($match[1])] = $raw[0] === '"' || $raw[0] === "'"
                    ? substr($raw, 1, -1)
                    : $raw;
            }
        }

        return $attrs;
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
