<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Immutable allowlist of file extensions treated as local assets.
 *
 * PHP files are never assets regardless of configuration: calling out a URL
 * with a PHP extension means capture must not serve it as a static file.
 */
final class AssetExtensions
{
    /** @var list<string> ASSET_EXTENSIONS constant. */
    public const DEFAULTS = [
        'css',
        'js',
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
        'avif',
        'svg',
        'ico',
        'woff',
        'woff2',
        'ttf',
        'otf',
        'eot',
        'json',
        'xml',
        'map',
        'pdf',
        'mp3',
        'mp4',
        'webm',
    ];

    private const FORBIDDEN = ['php', 'phtml'];

    /** @var array<string, true> */
    private array $extensions;

    /**
     * @param list<string>|null $extensions override the defaults; php/phtml
     *                                      are always stripped from the set.
     */
    public function __construct(?array $extensions = null)
    {
        $extensions ??= self::DEFAULTS;

        foreach ($extensions as $extension) {
            $extension = strtolower(ltrim($extension, '.'));
            if ($extension !== '' && !in_array($extension, self::FORBIDDEN, true)) {
                $this->extensions[$extension] = true;
            }
        }
    }

    public function contains(string $extension): bool
    {
        return isset($this->extensions[strtolower(ltrim($extension, '.'))]);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->extensions);
    }
}
