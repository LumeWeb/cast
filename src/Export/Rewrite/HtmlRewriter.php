<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Masterminds\HTML5;

/**
 * HTML pipeline for the offline-zip destination.
 *
 * HTML is parsed and serialized with an HTML5 implementation so rewriting is
 * based on the browser-visible document tree rather than a partial tag scanner.
 * Cast still owns the rewrite policy: which attributes are URL-bearing, origin
 * admission, offline path calculation, queueing, CSS/JS/JSON delegation, and
 * WordPress head cleanup. Serialization details such as quote style, entity
 * representation, and implicit HTML5 structure are intentionally not a
 * contract.
 */
final class HtmlRewriter
{
    /** @var list<string> */
    private const URL_ATTRIBUTES = [
        'href',
        'src',
        'poster',
        'action',
        'formaction',
        'data',
        'xlink:href',
    ];

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
        $html5 = new HTML5();
        $document = $html5->loadHTML($html);
        $xpath = new DOMXPath($document);
        $elements = $xpath->query('//*');

        if ($elements !== false) {
            foreach ($elements as $element) {
                if (!$element instanceof DOMElement) {
                    continue;
                }

                $this->rewriteAttributes($element, $context);
                $this->rewriteRawContent($element, $document, $context);
            }
        }

        return $this->headStripper->strip($html5->saveHTML($document), $context);
    }

    private function rewriteAttributes(DOMElement $element, RewriteContext $context): void
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[] = [$attribute->name, $attribute->value];
        }

        foreach ($attributes as [$name, $value]) {
            $lowerName = strtolower($name);
            $rewritten = $this->rewriteAttribute($lowerName, $value, $context);
            if ($rewritten !== $value) {
                $element->setAttribute($name, $rewritten);
            }
        }
    }

    private function rewriteAttribute(string $name, string $value, RewriteContext $context): string
    {
        if ($name === 'style') {
            return $this->cssRewriter->rewrite($value, $context);
        }

        if (str_ends_with($name, 'srcset')) {
            return $this->srcsetSplitter->rewrite(
                $value,
                fn (string $url): string => $this->convertUrl($url, $context),
            );
        }

        if ($name === 'content') {
            return $this->looksLikeUrl($value) ? $this->convertUrl($value, $context) : $value;
        }

        if (\in_array($name, self::URL_ATTRIBUTES, true)) {
            return $this->convertUrl($value, $context);
        }

        if (!str_starts_with($name, 'data-')) {
            return $value;
        }

        $trimmed = ltrim($value);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[') && json_decode($value) !== null) {
            return $this->jsonRewriter->rewrite($value, $context);
        }

        return $this->looksLikeUrl($value) ? $this->convertUrl($value, $context) : $value;
    }

    private function rewriteRawContent(DOMElement $element, DOMDocument $document, RewriteContext $context): void
    {
        $tagName = strtolower($element->tagName);
        if ($tagName !== 'script' && $tagName !== 'style') {
            return;
        }

        $content = $element->textContent;
        $rewritten = $tagName === 'script'
            ? $this->jsRewriter->rewrite($content, $context)
            : $this->cssRewriter->rewrite($content, $context);

        if ($rewritten === $content) {
            return;
        }

        while ($element->firstChild instanceof DOMNode) {
            $element->removeChild($element->firstChild);
        }
        $element->appendChild($document->createTextNode($rewritten));
    }

    /**
     * A value is treated as a URL reference when it is an absolute/protocol-
     * relative web URL or a root-relative path with at least one '/'.
     */
    private function looksLikeUrl(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return preg_match('{^(?:[a-z][a-z0-9+.\-]*:)?//}i', $value) === 1
            || (str_starts_with($value, '/') && str_contains(substr($value, 1), '/'));
    }

    private function convertUrl(string $value, RewriteContext $context): string
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
