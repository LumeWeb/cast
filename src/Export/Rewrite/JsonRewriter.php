<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

/**
 * JSON rewriter for JSON-in-attributes and Elementor JSON scripts.
 *
 * Decodes payloads that start with '{' or '[', walks every URL-looking string
 * key and value (heuristic: contains a '/'), resolves each through the same
 * UrlConverter/OriginPolicy pipeline as HTML/CSS so in-origin URLs are both
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

    private function walk(mixed $value, RewriteContext $context): mixed
    {
        if (!\is_array($value)) {
            if (\is_string($value) && $this->looksLikeUrl($value)) {
                return $this->convertAndPresent($value, $context);
            }

            return $value;
        }

        $srcsetKeys = ['srcset', 'imagesrcset'];
        $result = [];
        foreach ($value as $key => $item) {
            $newKey = \is_string($key) ? $this->rewrittenKey($key, $context) : $key;

            if (\is_string($newKey) && \in_array(strtolower($newKey), $srcsetKeys, true)) {
                $result[$newKey] = $this->rewriteSrcset($item, $context);
                continue;
            }

            $result[$newKey] = $this->walk($item, $context);
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
     * A string is a URL candidate for the JSON walk when it contains a '/'.
     * This is the practical minimal boundary: keys/vals without a slash
     * (titles, counts, booleans) are never touched, while absolute,
     * protocol-relative and relative references are resolved like any HTML url.
     */
    private function looksLikeUrl(string $value): bool
    {
        return str_contains($value, '/');
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
