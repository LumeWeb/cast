<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Reads the uploads root from WordPress without exposing WordPress globals to
 * the wrap-up deletion logic.
 */
final class WordPressWrapupEnvironment implements WrapupEnvironment
{
    public function uploadsDirectory(): string
    {
        $uploads = wp_upload_dir();
        if (!is_array($uploads) || !isset($uploads['basedir']) || !is_string($uploads['basedir'])) {
            throw new \RuntimeException('WordPress did not provide an uploads directory.');
        }

        return $uploads['basedir'];
    }
}
