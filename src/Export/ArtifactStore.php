<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The artifact store abstraction behind retention GC: enumeration of the per-run
 * artifact ZIPs under the shared `cast-exports` directory and jail-checked
 * deletion of a single artifact.
 *
 * The store is the only place that knows how artifacts are laid out and how
 * they are safely removed. Its jail contract is absolute: only a regular
 * `*.zip` whose realpath lives inside the realpath of the cast-exports root
 * may be deleted — the shared `manifest.json` and any non-zip entry are never
 * deletable, and a symlink that resolves outside the jail is never followed.
 * The pure {@see ArtifactRetentionService} consumes these two operations and
 * makes no filesystem calls of its own.
 */
interface ArtifactStore
{
    /**
     * The canonical realpath of the cast-exports jail root, or null when the
     * WordPress uploads root / the exports directory cannot be established
     * (a fresh site with no artifacts yet resolves null so GC no-ops safely).
     */
    public function jailRoot(): ?string;

    /**
     * Every regular `.zip` artifact under the jail, resolved to its realpath
     * and stat'ed, sorted by name. Entries whose realpath escapes the jail —
     * symlink plants — are never surfaced. When the jail does not exist the
     * list is empty.
     *
     * @return list<ArtifactFile>
     */
    public function listZipArtifacts(): array;

    /**
     * Jail-checked deletion of one artifact ZIP. Returns false — and removes
     * nothing — unless the resolved target is a regular file named `*.zip`
     * living inside the resolved jail root (a missing file, the shared
     * manifest.json, a non-zip entry, a path outside the jail and a symlink
     * target outside the jail are all refused).
     */
    public function delete(string $path): bool;
}
