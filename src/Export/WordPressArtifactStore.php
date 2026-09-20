<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * WordPress {@see ArtifactStore} adapter over the uploads-root `cast-exports`
 * sibling directory (the same jail {@see WordPressPackEnvironment} writes the
 * per-run artifact ZIPs into).
 *
 * The resolved realpath of the cast-exports directory is the deletion jail:
 * deletion is refused unless the target resolves to a regular `*.zip` whose
 * realpath stays inside that jail, so a symlink or traversal can never remove
 * a file outside the cast-exports directory — and the shared `manifest.json`
 * (a `.json` file, never a `*.zip`) can never be deleted. An absent uploads
 * root / exports directory resolves to a null jail on which GC no-ops safely
 * instead of failing loudly, unlike pack which genuinely needs the path to
 * write an artifact.
 */
final class WordPressArtifactStore implements ArtifactStore
{
    public const EXPORTS_DIR = 'cast-exports';

    /**
     * @param string|null $uploadsRoot optional uploads root for tests; when
     *                                 null the WordPress uploads directory is
     *                                 resolved live through wp_upload_dir()
     */
    public function __construct(private readonly ?string $uploadsRoot = null)
    {
    }

    public function jailRoot(): ?string
    {
        $uploads = $this->uploadsDirectory();
        if ($uploads === null) {
            return null;
        }

        clearstatcache(true);

        $root = realpath(rtrim($uploads, '/\\') . DIRECTORY_SEPARATOR . self::EXPORTS_DIR);

        return is_string($root) && is_dir($root) ? $root : null;
    }

    public function listZipArtifacts(): array
    {
        $jail = $this->jailRoot();
        if ($jail === null) {
            return [];
        }

        $artifacts = [];
        $entries = glob($jail . DIRECTORY_SEPARATOR . '*.zip') ?: [];
        foreach ($entries as $entry) {
            if (!is_file($entry)) {
                continue;
            }

            clearstatcache(true, $entry);
            $real = realpath($entry);
            if ($real === false || !self::isInside($real, $jail)) {
                // A symlink plant resolving outside the jail is never surfaced
                // as a collectable artifact.
                continue;
            }

            $artifacts[] = new ArtifactFile(
                basename($real),
                $real,
                (int) (filemtime($real) ?: 0),
            );
        }

        usort($artifacts, static fn (ArtifactFile $a, ArtifactFile $b): int => strcmp($a->name, $b->name));

        return $artifacts;
    }

    public function delete(string $path): bool
    {
        $jail = $this->jailRoot();
        if ($jail === null) {
            return false;
        }

        if (basename($path) === '') {
            return false;
        }

        // Only a `*.zip` artifact is ever deletable; the shared manifest.json
        // and every other non-zip entry in the jail are permanently protected.
        if (!str_ends_with(basename($path), '.zip')) {
            return false;
        }

        clearstatcache(true, $path);
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            return false;
        }

        // The canonical target must stay inside the jail realpath — a symlink
        // resolving outside is refused — and must still be a `.zip` after
        // resolution.
        if (!self::isInside($real, $jail)) {
            return false;
        }
        if (!str_ends_with(basename($real), '.zip')) {
            return false;
        }

        clearstatcache(true, $real);

        return @unlink($real);
    }

    /**
     * The resolved WordPress uploads directory, or null when WordPress did not
     * provide one. Tests inject the root directly, so wp_upload_dir() is only
     * consulted when no root was provided.
     */
    private function uploadsDirectory(): ?string
    {
        if ($this->uploadsRoot !== null) {
            return $this->uploadsRoot;
        }

        $uploads = wp_upload_dir();
        if (!is_array($uploads) || !isset($uploads['basedir']) || !is_string($uploads['basedir'])) {
            return null;
        }

        return $uploads['basedir'];
    }

    private static function isInside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/') . DIRECTORY_SEPARATOR);
    }
}
