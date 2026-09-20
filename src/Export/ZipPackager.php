<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

use ZipArchive;

/**
 * ZIP64-capable artifact packer: stages every file of the jailed work
 * directory into a ZipArchive placed at a fixed artifact path outside the work
 * tree. Entry names are normalized forward-slash relative paths; each staged
 * file's realpath must stay under the work-dir realpath (jail), otherwise it
 * is skipped with a warning. Site files are added in bounded batches, and the
 * manifest JSON is appended only after every site file exists — as the last
 * zip entry and as an adjacent file next to the zip.
 */
final class ZipPackager
{
    public const DEFAULT_BATCH_SIZE = 2500;
    public const MIN_BATCH_SIZE = 1;
    public const MAX_BATCH_SIZE = 10000;
    public const MANIFEST_ENTRY = 'manifest.json';

    /**
     * The minimal valid empty ZIP: the 22-byte end-of-central-directory record
     * (PK\x05\x06, zero disks/entries/sizes, no comment). ZipArchive::close()
     * writes nothing for a zero-file archive, so the empty artifact is written
     * verbatim.
     */
    private const EMPTY_EOCD = "\x50\x4b\x05\x06\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";

    private readonly int $batchSize;

    public function __construct(int $batchSize = self::DEFAULT_BATCH_SIZE)
    {
        $this->batchSize = max(self::MIN_BATCH_SIZE, min(self::MAX_BATCH_SIZE, $batchSize));
    }

    public function batchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * @param array<string, mixed> $manifest pre-pack caller data (run_id,
     *                                       origin, settings…) merged into the
     *                                       on-disk manifest; packer-owned
     *                                       keys always win
     * @param list<string>         $warnings pre-existing findings (leftover
     *                                       origins, broken references) that
     *                                       fold into the outcome status
     */
    public function pack(string $workDir, string $zipPath, array $manifest = [], array $warnings = []): PackResult
    {
        if (!extension_loaded('zip')) {
            return PackResult::failed($zipPath, ['PHP zip extension required']);
        }

        $root = realpath($workDir);
        if ($root === false || !is_dir($root)) {
            return PackResult::failed($zipPath, [sprintf('Work directory does not exist: %s', $workDir)]);
        }

        if (!$this->isOutsideWorkDir($root, $zipPath)) {
            return PackResult::failed($zipPath, ['Artifact path must be outside the work directory']);
        }

        $zipDir = dirname($zipPath);
        if (!is_dir($zipDir) && !@mkdir($zipDir, 0777, true) && !is_dir($zipDir)) {
            return PackResult::failed($zipPath, [sprintf('Cannot create artifact directory: %s', $zipDir)]);
        }

        [$entries, $skips] = $this->snapshot($root);
        $warnings = array_merge($warnings, array_values($skips));

        // The manifest is authored once and reused everywhere: it is the last
        // entry inside the ZIP (written only after every site file exists) and
        // the adjacent file next to the zip. Same JSON in both places.
        $manifestPath = $zipDir . '/' . self::MANIFEST_ENTRY;
        $json = $this->encode(array_merge($manifest, $this->payload($entries, $skips)));

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return PackResult::failed($zipPath, [sprintf('Cannot open artifact for writing: %s', $zipPath)]);
        }

        $added = 0;
        if ($entries === []) {
            // Zero site files: emit the minimal empty ZIP manually (ZipArchive
            // writes nothing for a zero-file archive); the manifest still
            // describes the empty artifact.
            $zip->close();
            file_put_contents($zipPath, self::EMPTY_EOCD);
            $bytes = strlen(self::EMPTY_EOCD);
        } else {
            foreach (array_chunk($entries, $this->batchSize) as $batch) {
                foreach ($batch as [$relative, $absolute]) {
                    if ($zip->addFile($absolute, $relative) === true) {
                        ++$added;
                    } else {
                        $warnings[] = sprintf('Skipped %s: could not add to archive', $relative);
                    }
                }
            }

            // Manifest is appended strictly after every site file exists.
            $zip->addFromString(self::MANIFEST_ENTRY, $json);
            $zip->close();
            $bytes = (int) (filesize($zipPath) ?: 0);
        }

        // Adjacent manifest, next to the zip — same bytes as the in-ZIP copy.
        file_put_contents($manifestPath, $json);

        $status = $warnings === [] ? PackStatus::Completed : PackStatus::CompletedWithWarnings;

        return new PackResult($status, $added, count($skips), $bytes, $zipPath, $manifestPath, $warnings);
    }

    /**
     * Snapshot the jailed work tree: returns the packable [relative, absolute]
     * pairs (sorted by relative name) and a map of relative name => skip reason
     * for entries that violate the path policy or whose realpath escapes the
     * work-dir jail.
     *
     * @return array{0: list<array{0: string, 1: string}>, 1: array<string, string>}
     */
    private function snapshot(string $root): array
    {
        $entries = [];
        $skips = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $entry) {
            if (!$entry->isFile() && !$entry->isLink()) {
                continue;
            }

            $relative = ArtifactPath::normalize(str_replace($root . DIRECTORY_SEPARATOR, '', $entry->getPathname()));
            $violation = ArtifactPath::violation($relative);
            if ($violation !== null) {
                $skips[$relative] = sprintf('Skipped %s: unsafe path (%s)', $relative, $violation);

                continue;
            }

            $real = realpath($entry->getPathname());
            if ($real === false || !$this->isInside($real, $root)) {
                $skips[$relative] = sprintf('Skipped %s: resolved outside the work-directory jail', $relative);

                continue;
            }

            $entries[] = [$relative, $real];
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return [$entries, $skips];
    }

    /**
     * @param list<array{0: string, 1: string}> $entries
     * @param array<string, string>             $skips
     *
     * @return array<string, bool|int|string>
     */
    private function payload(array $entries, array $skips): array
    {
        return [
            'packed_files' => count($entries),
            'skipped_files' => count($skips),
            'empty_artifact' => $entries === [],
            'finished_at' => gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function isOutsideWorkDir(string $root, string $zipPath): bool
    {
        $zipDir = realpath(dirname($zipPath));
        if ($zipDir === false) {
            return true;
        }
        $artifact = $zipDir . DIRECTORY_SEPARATOR . basename($zipPath);

        return !$this->isInside($artifact, $root);
    }

    private function isInside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/') . DIRECTORY_SEPARATOR);
    }
}
