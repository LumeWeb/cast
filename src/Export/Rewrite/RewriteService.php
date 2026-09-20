<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

use LumeWeb\Cast\Export\WorkItem;
use LumeWeb\Cast\Export\WorkItemKind;

/**
 * Rewrite service facade for the offline-zip destination.
 *
 * Dispatches a captured WorkItem's body to the exact rewriter for its content
 * kind: HTML pages (with the head-strip), CSS/JS/JSON/XML assets rewritten
 * against their own document URL, text files, and everything else (binary
 * fixed assets) passed through untouched. This rewrite service only supports
 * DestinationMode::OfflineZip — any other mode string is rejected at
 * construction. After the per-kind pass the leftover-origin reporter runs as
 * the last step on text-like content, so an origin URL that survives
 * parse-and-replace becomes a manifest warning, never a global host smash.
 * Pure PHP/file-oriented; no ZIP/publish/UI.
 */
final class RewriteService
{
    private DestinationMode $mode;

    public function __construct(
        string $mode = 'offline-zip',
        private readonly HtmlRewriter $htmlRewriter = new HtmlRewriter(),
        private readonly CssRewriter $cssRewriter = new CssRewriter(),
        private readonly JsRewriter $jsRewriter = new JsRewriter(),
        private readonly JsonRewriter $jsonRewriter = new JsonRewriter(),
        private readonly XmlRewriter $xmlRewriter = new XmlRewriter(),
        private readonly TextRewriter $textRewriter = new TextRewriter(),
        private readonly LeftoverOriginReporter $leftoverOriginReporter = new LeftoverOriginReporter(),
    ) {
        $this->mode = DestinationMode::fromString($mode);
    }

    public function mode(): DestinationMode
    {
        return $this->mode;
    }

    public function rewrite(WorkItem $item, string $content, RewriteContext $context, WarningCollector $warnings): string
    {
        $contentType = $this->contentTypeFor($item);

        $rewritten = match ($contentType) {
            'html' => $this->htmlRewriter->rewrite($content, $context),
            'css' => $this->cssRewriter->rewrite($content, $context),
            'js' => $this->jsRewriter->rewrite($content, $context),
            'json' => $this->jsonRewriter->rewrite($content, $context),
            'xml' => $this->xmlRewriter->rewrite($content, $context),
            'text' => $this->textRewriter->rewrite($content, $context),
            default => $content, // binary / unknown: fixed asset, pass through
        };

        if ($contentType === 'binary') {
            return $rewritten;
        }

        // Last pass: report (never smash) any origin URL that survived the
        // per-kind parser. Binary assets skip this to avoid byte-scan noise.
        return $this->leftoverOriginReporter->report($rewritten, $context, $warnings);
    }

    private function contentTypeFor(WorkItem $item): string
    {
        return match ($item->kind()) {
            WorkItemKind::Page => 'html',
            WorkItemKind::Text => 'text',
            WorkItemKind::Redirect => 'html',
            WorkItemKind::Asset => $this->assetContentType($item),
        };
    }

    private function assetContentType(WorkItem $item): string
    {
        $extension = strtolower(pathinfo($item->outputPath(), PATHINFO_EXTENSION));

        return match ($extension) {
            'css' => 'css',
            'js', 'mjs' => 'js',
            'json' => 'json',
            'xml', 'rss', 'atom' => 'xml',
            default => 'binary',
        };
    }
}
