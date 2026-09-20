<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Reads the uploads root from WordPress without exposing WordPress globals to
 * the filesystem setup logic.
 */
final class WordPressSetupEnvironment implements SetupEnvironment
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
