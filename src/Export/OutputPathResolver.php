<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Deterministic destination path inside the work directory for a canonical
 * URL and its classified kind. Pages that keep a query collate under a hashed
 * subdirectory so a search URL can never overwrite the pretty page. The hash
 * mixes in the path so two distinct pages sharing an identical query string
 * never map onto the same file.
 */
final class OutputPathResolver
{
    public function resolve(Url $url, WorkItemKind $kind): string
    {
        if ($kind === WorkItemKind::Page && $url->hasQuery()) {
            $hash = substr(md5($url->path() . '?' . $url->query()), 0, 12);

            return '__qs/' . $hash . '/index.html';
        }

        $path = ltrim($url->path(), '/');
        if ($path === '') {
            return 'index.html';
        }

        if ($kind === WorkItemKind::Asset || $kind === WorkItemKind::Text) {
            return $path;
        }

        return rtrim($path, '/') . '/index.html';
    }
}
