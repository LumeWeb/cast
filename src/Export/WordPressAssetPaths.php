<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Builds the {@see WordPressAssetRoots} from live WordPress paths. WordPress
 * globals (wp_upload_dir(), WP_CONTENT_DIR/WP_PLUGIN_DIR/ABSPATH) live here and
 * nowhere in the pure jail/source classes; an integration component constructs the
 * source with the export work directory appended.
 */
final class WordPressAssetPaths
{
    public static function roots(): WordPressAssetRoots
    {
        $uploads = wp_upload_dir();
        $uploadsDir = is_array($uploads) && isset($uploads['basedir']) && is_string($uploads['basedir'])
            ? $uploads['basedir']
            : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/uploads' : '');

        $contentDir = defined('WP_CONTENT_DIR') ? (string) WP_CONTENT_DIR : dirname($uploadsDir);
        $pluginsDir = defined('WP_PLUGIN_DIR') ? (string) WP_PLUGIN_DIR : $contentDir . '/plugins';
        $abspath = defined('ABSPATH') ? rtrim((string) ABSPATH, '/\\') : dirname($contentDir);

        return new WordPressAssetRoots(
            $contentDir,
            $uploadsDir,
            $pluginsDir,
            $contentDir . '/themes',
            $abspath,
        );
    }
}
