<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The jail roots the local asset source serves from: wp-content, uploads,
 * plugins, themes, and an ABSPATH-pinned wp-includes safe subset. map() turns a
 * URL path into the most-specific {root, relative} pair, so a subdirectory
 * WordPress install (paths prefixed with /blog/) still resolves. Anything not
 * under a known web-visible prefix returns null and the caller falls back to HTTP.
 */
final class WordPressAssetRoots
{
    public function __construct(
        private readonly string $contentDir,
        private readonly string $uploadsDir,
        private readonly string $pluginsDir,
        private readonly string $themesDir,
        private readonly string $abspath,
    ) {
    }

    /**
     * @return array{root: string, relative: string}|null
     */
    public function map(string $urlPath): ?array
    {
        // A bare web directory root is never a file: the uploads/plugins/
        // themes/content/includes directories themselves, with or without a
        // trailing slash, resolve to nothing so the caller falls back to HTTP.
        // Without this guard `/wp-content/uploads` would slip through to the
        // generic `/wp-content/` rule with relative 'uploads'.
        $webDirectoryRoots = [
            '/wp-content/uploads',
            '/wp-content/plugins',
            '/wp-content/themes',
            '/wp-content',
            '/wp-includes',
        ];
        if (in_array(rtrim($urlPath, '/'), $webDirectoryRoots, true)) {
            return null;
        }

        $rules = [
            '/wp-content/uploads/' => $this->uploadsDir,
            '/wp-content/plugins/' => $this->pluginsDir,
            '/wp-content/themes/' => $this->themesDir,
            '/wp-content/' => $this->contentDir,
            '/wp-includes/' => $this->abspath . '/wp-includes',
        ];

        foreach ($rules as $prefix => $root) {
            $at = strpos($urlPath, $prefix);
            if ($at === false) {
                continue;
            }

            $relative = substr($urlPath, $at + strlen($prefix));
            if ($relative === '') {
                return null;
            }

            return ['root' => $root, 'relative' => $relative];
        }

        return null;
    }
}
