<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Read-only view over the staged work tree (the artifact being validated and
 * packed). Pure callers read relative '/' paths and textual content through
 * this boundary; nothing here mutates the tree.
 */
interface ArtifactTree
{
    /**
     * All staged files as deterministic, forward-slash relative paths.
     *
     * @return list<string>
     */
    public function paths(): array;

    public function exists(string $relativePath): bool;

    /**
     * Null when the path is missing, is a directory, or cannot be read.
     */
    public function readText(string $relativePath): ?string;

    /**
     * True for staged files whose content is text and should be scanned for
     * references/origin leftovers (html/css/js/json/xml/txt/svg...).
     */
    public function isTextLike(string $relativePath): bool;
}
