<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Runtime WordPress facts the loopback probe needs, isolated behind an interface
 * so the pure {@see ProbeStage} can be exercised without loading WordPress.
 * A concrete {@see WordPressProbeEnvironment} adapter reads these from
 * home_url()/get_option()/extension_loaded()/wp_upload_dir(); unit tests inject
 * a scripted fake instead.
 */
interface ProbeEnvironment
{
    /**
     * The WordPress home URL including the trailing slash, i.e. home_url('/').
     */
    public function homeUrl(): string;

    /**
     * The configured permalink structure ('' when plain ?p= permalinks are in
     * use, which the probe must refuse before touching the network).
     */
    public function permalinkStructure(): string;

    /**
     * Whether the PHP XML extension is loaded (sitemap parsing needs it).
     */
    public function xmlLoaded(): bool;

    /**
     * Whether the PHP DOM extension is loaded (sitemap parsing needs it).
     */
    public function domLoaded(): bool;

    /**
     * Whether the PHP zip extension is loaded (packing the artifact needs it).
     */
    public function zipLoaded(): bool;

    /**
     * Whether the WordPress uploads directory (work-dir parent) is writable.
     */
    public function uploadsWritable(): bool;
}
