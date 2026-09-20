<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Decides what a canonical URL is to capture: an asset when its basename has
 * an allowlisted extension, a text file for the well-known fixed files, and a
 * page otherwise.
 */
final class UrlClassifier
{
    private const TEXT_FILES = ['robots.txt', '_redirects', '_headers', 'llms.txt'];

    public function classify(Url $url, AssetExtensions $assetExtensions): WorkItemKind
    {
        $basename = strtolower(basename($url->path()));

        if (in_array($basename, self::TEXT_FILES, true)) {
            return WorkItemKind::Text;
        }

        $extension = pathinfo($basename, PATHINFO_EXTENSION);
        if ($extension !== '' && $assetExtensions->contains($extension)) {
            return WorkItemKind::Asset;
        }

        return WorkItemKind::Page;
    }
}
