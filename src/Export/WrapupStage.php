<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The wrap-up stage: the final bounded unit of an export run, reached only
 * after the pack boundary finished. Validates the artifact evidence the pack
 * stage left behind (ZIP exists, minimum 22 bytes, opens, manifest exists,
 * expected entry count, the tolerated empty artifact) and then jail-checked
 * recursively deletes the jailed work directory — on success and on integrity
 * failure alike — while always preserving the ZIP + manifest for retention.
 *
 * One bounded unit per tick: validation and deletion happen in a single call
 * and the stage reports done('') at its fixed point. The work directory is
 * deleted both when the artifact is sound and when its integrity check fails;
 * only a jail escape / deletion refusal leaves the work directory behind. The
 * ZIP and manifest are never deleted. The pack warnings are carried forward on
 * the returned result so a completed-with-warnings pack keeps the run flagged.
 */
final class WrapupStage implements PipelineStage
{
    private const MIN_ZIP_BYTES = 22;

    public function __construct(
        private readonly WrapupEnvironment $environment,
        private readonly PipelineState $state,
    ) {
    }

    public function key(): PipelineStageKey
    {
        return PipelineStageKey::Wrapup;
    }

    public function execute(string $cursor): StageResult
    {
        if ($this->state->probe === null) {
            return StageResult::fail('Wrapup requires a successful probe first.');
        }

        $workDir = $this->state->setup?->workDir;
        if ($workDir === null) {
            return StageResult::fail('Wrapup requires a successful setup first.');
        }

        $pack = $this->state->pack;
        if ($pack === null) {
            return StageResult::fail('Wrapup requires a successful pack first.');
        }

        $integrityErrors = $this->validateArtifact($pack);

        // Jail-checked recursive deletion; the jail is the uploads root and
        // the work directory itself, so an escape is never followed.
        $deletionFailure = $this->deleteJailedWorkDirectory($workDir);

        if ($deletionFailure !== null) {
            $this->state->wrapup = WrapupResult::deletionFailure($deletionFailure, $pack->warnings);

            return StageResult::fail($deletionFailure, '', 0, $pack->warnings);
        }

        if ($integrityErrors !== []) {
            $this->state->wrapup = WrapupResult::integrityFailure($integrityErrors, true, $pack->warnings);

            return StageResult::fail(
                sprintf('Wrapup integrity failure: %s', implode(' ', $integrityErrors)),
                '',
                0,
                $pack->warnings,
            );
        }

        $this->state->wrapup = WrapupResult::completed(true, $pack->warnings);

        return StageResult::done('', 0, $pack->warnings);
    }

    /**
     * @return list<string> integrity finding messages; empty when the artifact
     *                      is sound
     */
    private function validateArtifact(PackResult $pack): array
    {
        // The artifact may have been written by a different tick; never trust
        // a stale stat/realpath cache for the integrity evidence.
        clearstatcache(true);

        $errors = [];

        if (!is_file($pack->zipPath)) {
            $errors[] = 'Artifact ZIP file is missing.';
        } else {
            $size = @filesize($pack->zipPath);
            if ($size === false || $size < self::MIN_ZIP_BYTES) {
                $errors[] = 'Artifact ZIP is smaller than the 22-byte minimum supported archive.';
            } else {
                $zip = new \ZipArchive();
                if (@$zip->open($pack->zipPath) !== true) {
                    $errors[] = 'Artifact ZIP failed the ZipArchive integrity check.';
                } else {
                    $actualEntries = (int) $zip->numFiles;
                    $zip->close();

                    // Every site file plus the trailing manifest entry — or,
                    // for the tolerated empty artifact, no entries at all in
                    // the 22-byte EOCD archive.
                    $expectedEntries = $pack->filesAdded === 0 ? 0 : $pack->filesAdded + 1;
                    if ($actualEntries !== $expectedEntries) {
                        $errors[] = sprintf(
                            'Artifact ZIP entry count mismatch: expected %d, found %d.',
                            $expectedEntries,
                            $actualEntries,
                        );
                    }
                }
            }
        }

        if ($pack->manifestPath === null) {
            $errors[] = 'Artifact manifest path is missing.';
        } elseif (!is_file($pack->manifestPath)) {
            $errors[] = 'Artifact manifest file is missing.';
        } else {
            $manifest = json_decode((string) @file_get_contents($pack->manifestPath), true);
            if (!is_array($manifest)) {
                $errors[] = 'Artifact manifest is not a valid JSON document.';
            } elseif ((int) ($manifest['packed_files'] ?? -1) !== $pack->filesAdded) {
                $errors[] = 'Artifact manifest entry count disagrees with the pack result.';
            }
        }

        return $errors;
    }

    /**
     * Recursively delete the jailed work directory or, when it cannot be
     * safely touched, return a plain-language reason. The deletion is
     * jail-checked twice: the work directory itself must resolve inside the
     * uploads jail, and no walked entry may resolve outside the work
     * directory's own realpath — symlinks are never followed (only the link
     * entry itself is removed), so an escape plant can never delete its
     * outside target.
     */
    private function deleteJailedWorkDirectory(string $workDir): ?string
    {
        // The work directory may have been touched between stages; never
        // trust a stale stat/realpath cache when deciding what to delete.
        clearstatcache(true);

        $uploads = realpath($this->environment->uploadsDirectory());
        if ($uploads === false || !is_dir($uploads)) {
            return 'Wrapup could not resolve its uploads jail root.';
        }

        $root = realpath($workDir);
        if ($root === false || !is_dir($root)) {
            return 'Wrapup work directory is missing or not a directory.';
        }

        if ($root === $uploads || !$this->isInside($root, $uploads)) {
            return 'Wrapup work directory escapes the uploads jail.';
        }

        if (!$this->removeTreeInsideJail($root)) {
            return 'Wrapup refused to delete a work-directory entry that escapes the jail.';
        }

        return null;
    }

    /**
     * Remove every nested entry of $root (children first) without ever
     * following a symlink to outside the jail. Returns false — and stops —
     * as soon as an entry cannot be resolved inside the jail or cannot be
     * removed, so nothing outside is ever touched.
     */
    private function removeTreeInsideJail(string $root): bool
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $path = $entry->getPathname();

            if (is_link($path)) {
                // Never follow a symlink: only remove the link itself, so its
                // target (possibly outside the jail) stays untouched.
                if (!@unlink($path)) {
                    return false;
                }

                continue;
            }

            $real = realpath($path);
            if ($real === false || !$this->isInside($real, $root)) {
                return false;
            }

            if ($entry->isDir() ? !@rmdir($path) : !@unlink($path)) {
                return false;
            }
        }

        return @rmdir($root);
    }

    private function isInside(string $path, string $root): bool
    {
        return $path !== $root && str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }
}
