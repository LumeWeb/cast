<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\ArtifactPath;
use LumeWeb\Cast\Export\ArtifactTree;

/**
 * In-memory ArtifactTree for pure validator/reference tests: a map of relative
 * path => text content. Does not touch the filesystem.
 */
final class ArrayArtifactTree implements ArtifactTree
{
    /**
     * @param array<string, string>        $files    relative path => contents
     * @param list<string>|null            $textLike override of text-like paths (default: html/css/js/json/xml/txt/svg)
     * @param list<string>                 $extra    paths to list without content (e.g. intentional dummies)
     */
    public function __construct(
        private readonly array $files,
        private readonly ?array $textLike = null,
        private readonly array $extra = [],
    ) {
    }

    public function paths(): array
    {
        $paths = array_merge(array_keys($this->files), $this->extra);
        $paths = array_map(static fn (string $p): string => ArtifactPath::normalize($p), $paths);
        // Duplicate normalized paths are kept so the validator's duplicate
        // output-path detection sees them (e.g. 'css/style.css' + 'css//style.css').
        $paths = array_values(array_filter($paths, static fn (string $p): bool => $p !== ''));
        sort($paths, SORT_STRING);

        return $paths;
    }

    public function exists(string $relativePath): bool
    {
        return array_key_exists(ArtifactPath::normalize($relativePath), $this->files)
            || in_array(ArtifactPath::normalize($relativePath), $this->extra, true);
    }

    public function readText(string $relativePath): ?string
    {
        $normalized = ArtifactPath::normalize($relativePath);

        return $this->files[$normalized] ?? null;
    }

    public function isTextLike(string $relativePath): bool
    {
        if ($this->textLike !== null) {
            return in_array(ArtifactPath::normalize($relativePath), $this->textLike, true);
        }

        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        return in_array($extension, ['html', 'htm', 'css', 'js', 'json', 'xml', 'txt', 'svg'], true);
    }
}
