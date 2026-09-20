<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

/**
 * JSON rewriter for JSON-in-attributes and Elementor JSON scripts.
 *
 * Decodes payloads that start with '{' or '[', walks every URL-looking string
 * key and value (reads-like-a-URL heuristic: scheme/root-/protocol-relative
 * references, explicit relative './' '../' references, or slash-bearing
 * strings under URL-context keys such as url/src/href/image/background),
 * resolves each through the same UrlConverter/OriginPolicy pipeline as
 * HTML/CSS so in-origin URLs are both
 * rewritten to offline './' paths and queued, and re-encodes valid JSON with
 * the default encoder (forward slashes escape to \/ like Elementor emits).
 * srcset/imagesrcset arrays (or single strings) are passed through the
 * comma-aware SrcsetSplitter so Cloudinary transform commas survive. Payloads
 * that look like JSON but fail to decode (e.g. unquoted keys) fall back to a
 * conservative regex over http(s):// and protocol-relative URLs. Plain text
 * that is not JSON-ish is left untouched — the leftover-origin warning is a
 * separate last pass, never a host smash.
 */
final class JsonRewriter
{
    public function __construct(
        private readonly UrlConverter $urlConverter = new UrlConverter(),
        private readonly SrcsetSplitter $srcsetSplitter = new SrcsetSplitter(),
    ) {
    }

    public function rewrite(string $payload, RewriteContext $context): string
    {
        $trimmed = ltrim($payload);
        if (!str_starts_with($trimmed, '{') && !str_starts_with($trimmed, '[')) {
            return $payload;
        }

        $decoded = json_decode($payload, true);

        if (\is_array($decoded)) {
            $rewritten = $this->walk($decoded, $context);
            $encoded = json_encode($rewritten);

            return $encoded === false ? $payload : $encoded;
        }

        return $this->regexFallback($payload, $context);
    }

    private function walk(mixed $value, RewriteContext $context, ?string $key = null): mixed
    {
        if (!\is_array($value)) {
            if (\is_string($value) && $this->looksLikeUrl($value, $key)) {
                return $this->convertAndPresent($value, $context);
            }

            return $value;
        }

        $srcsetKeys = ['srcset', 'imagesrcset'];
        $result = [];
        foreach ($value as $itemKey => $item) {
            $newKey = \is_string($itemKey) ? $this->rewrittenKey($itemKey, $context) : $itemKey;

            if (\is_string($newKey) && \in_array(strtolower($newKey), $srcsetKeys, true)) {
                $result[$newKey] = $this->rewriteSrcset($item, $context);
                continue;
            }

            $result[$newKey] = $this->walk($item, $context, \is_string($itemKey) ? $itemKey : $key);
        }

        return $result;
    }

    private function rewrittenKey(string $key, RewriteContext $context): string
    {
        return $this->looksLikeUrl($key) ? $this->convertAndPresent($key, $context) : $key;
    }

    private function rewriteSrcset(mixed $item, RewriteContext $context): mixed
    {
        $convert = fn (string $url): string => $this->convertAndPresent($url, $context);

        if (\is_string($item)) {
            return $this->srcsetSplitter->rewrite($item, $convert);
        }

        if (!\is_array($item)) {
            return $this->walk($item, $context);
        }

        $result = [];
        foreach ($item as $key => $candidate) {
            $result[$key] = \is_string($candidate)
                ? $this->srcsetSplitter->rewrite($candidate, $convert)
                : $this->walk($candidate, $context);
        }

        return $result;
    }

    /**
     * A string is a URL candidate for the JSON walk when it reads like a URL:
     * an absolute (http(s)://) or root-relative ('/...') or protocol-relative
     * ('//host/...') reference, an explicit relative ('./', '../') reference,
     * or — only under a URL-context key — any other slash-bearing string such
     * as "wp-content/x.png". Slash-bearing scalars under non-URL keys (dates
     * like "2024/01/15", ratios like "16/9") are never treated as URLs, so the
     * rewrite pass no longer corrupts them or queues bogus capture work items.
     *
     * @param string|null $key the enclosing JSON key that names this value;
     *                         null when the value has no key context (root
     *                         values, generated keys, numeric keys)
     */
    private function looksLikeUrl(string $value, ?string $key = null): bool
    {
        if (
            str_starts_with($value, 'http://')
            || str_starts_with($value, 'https://')
            || str_starts_with($value, '//')
            || str_starts_with($value, '/')
        ) {
            return true;
        }

        if (str_starts_with($value, './') || str_starts_with($value, '../')) {
            return true;
        }

        return $key !== null && $this->keyIsUrlContext($key) && str_contains($value, '/');
    }

    /**
     * JSON keys that name URL-bearing fields (matches the URL_ATTRIBUTES set
     * of the HTML rewriter): url/uri(s), src/href/srcset, image(s), background,
     * icon(s)/logo(s), manifest, endpoint and link. Matching is exact
     * whole-token equality: the key is split on camelCase boundaries and the
     * separators '-', '_', '.' and space, then each lowercase token must equal
     * a context word. camelCase conventions (backgroundUrl, featuredImageUrl)
     * and the separator forms (image_url, data-src) all count, while a key
     * that merely CONTAINS a URL word as a substring (curl, lexicon,
     * hyperlink) does not.
     */
    private function keyIsUrlContext(string $key): bool
    {
        $tokens = [
            'url', 'urls', 'uri', 'src', 'srcs', 'srcset', 'links', 'href',
            'imagesrcset', 'image', 'images', 'background', 'icon', 'icons',
            'logo', 'logos', 'manifest', 'endpoint', 'link',
        ];

        foreach ($this->splitKeyTokens($key) as $token) {
            if (\in_array($token, $tokens, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split a key into lowercase whole-word tokens on camelCase boundaries
     * (featuredImageUrl -> featured, image, url) and on the separators '-',
     * '_', '.' and space (image_url -> image, url). A key without any boundary
     * (imagesrcset) stays a single token, which must equal a context word
     * outright.
     *
     * @return list<string>
     */
    private function splitKeyTokens(string $key): array
    {
        $parts = preg_split('/(?<=[a-z0-9])(?=[A-Z])|[-_.\\s]+/', $key, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false || $parts === []) {
            return [strtolower($key)];
        }

        return array_map('strtolower', $parts);
    }

    private function convertAndPresent(string $value, RewriteContext $context): string
    {
        if ($value === '') {
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

    private function regexFallback(string $payload, RewriteContext $context): string
    {
        return preg_replace_callback(
            '{(?:(?:https?:)?//)[^\s"\'<>]+}i',
            fn (array $m): string => $this->convertAndPresent($m[0], $context),
            $payload
        ) ?? $payload;
    }
}
