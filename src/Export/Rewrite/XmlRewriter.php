<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

/**
 * XML rewriter for sitemaps and feeds.
 *
 * Absolute and protocol-relative URL tokens inside <loc>, <link>, <guid> and
 * friends are resolved through the queue check and converted to offline paths
 * relative to the XML file itself. The regex stops at whitespace, quotes, '<'
 * and CDATA ']]' so element text and markup never get swallowed. External
 * URLs — including schema namespace URLs — are rejected by the origin check and
 * come back byte-for-byte unchanged.
 */
final class XmlRewriter
{
    public function __construct(
        private readonly UrlConverter $urlConverter = new UrlConverter(),
    ) {
    }

    public function rewrite(string $xml, RewriteContext $context): string
    {
        return preg_replace_callback(
            '{(?:(?:https?:)?//)[^\s"\'<]+?(?=(?:\s|"|\'|<|$|]]))}i',
            fn (array $m): string => $this->convertAndPresent($m[0], $context),
            $xml
        ) ?? $xml;
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
