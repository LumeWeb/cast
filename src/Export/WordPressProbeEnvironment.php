<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Concrete {@see ProbeEnvironment} that reads the same facts the pure
 * {@see ProbeStage} prechecks through the real WordPress runtime surfaces:
 * home_url()/get_option()/extension_loaded()/wp_upload_dir(). It reflects
 * actual site state (work-dir writability, extension availability) without
 * ever touching the network itself or reading the incoming request's
 * authorization headers — the loopback probe is anonymous by contract.
 */
final class WordPressProbeEnvironment implements ProbeEnvironment
{
    public function homeUrl(): string
    {
        return home_url('/');
    }

    public function permalinkStructure(): string
    {
        return (string) get_option('permalink_structure', '');
    }

    public function xmlLoaded(): bool
    {
        return extension_loaded('xml');
    }

    public function domLoaded(): bool
    {
        return extension_loaded('dom');
    }

    public function zipLoaded(): bool
    {
        return extension_loaded('zip');
    }

    public function uploadsWritable(): bool
    {
        $dir = wp_upload_dir();

        if (!is_array($dir) || !empty($dir['error'])) {
            return false;
        }

        $basedir = $dir['basedir'] ?? null;

        return is_string($basedir) && $basedir !== '' && wp_is_writable($basedir);
    }
}
