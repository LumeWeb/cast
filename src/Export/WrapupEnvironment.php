<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Supplies the uploads jail root the wrap-up stage deletes inside of,
 * isolated behind an interface so the pure {@see WrapupStage} never loads
 * WordPress. The stage resolves the run's jailed work directory against this
 * root and refuses to touch anything that escapes it; a concrete WordPress
 * adapter later reads the uploads directory from WP. Unit tests inject a
 * scripted fake instead.
 */
interface WrapupEnvironment
{
    /**
     * Absolute uploads directory (the jail root the wrap-up stage deletes
     * inside of).
     */
    public function uploadsDirectory(): string;
}
