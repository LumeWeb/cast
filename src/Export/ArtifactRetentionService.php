<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Pure artifact retention GC service: given the {@see ArtifactStore} interface and
 * the {@see RunRepository}, it collects expired, unprotected artifact ZIPs and
 * returns a typed {@see RetentionSummary} plus warnings. The service makes no
 * filesystem calls of its own — enumeration, stat and jail-checked deletion
 * all live behind the store interface.
 *
 * Protection rules (a zip is never collected when any apply):
 *  - it belongs to the latest run (even when terminal — the single run slot's
 *    current artifact stays for publish-existing / inspection);
 *  - it belongs to a non-terminal run (a live pipeline still needs its
 *    artifact for resume);
 *  - it belongs to a resumable run — one that still owns a partial upload
 *    identifier a later request could resume.
 * The shared `manifest.json` is never enumerated (only `*.zip` artifacts are)
 * and the store refuses any non-zip deletion, so the manifest is protected by
 * construction and by the jail.
 */
final class ArtifactRetentionService
{
    public function __construct(
        private readonly ArtifactStore $store,
        private readonly RunRepository $runs,
    ) {
    }

    public function collect(int $now, RetentionPolicy $policy): RetentionSummary
    {
        if ($policy->isDisabled()) {
            return RetentionSummary::disabled($now, $policy);
        }

        $jail = $this->store->jailRoot();
        if ($jail === null) {
            return RetentionSummary::unavailable(
                $now,
                $policy,
                'Artifact retention could not resolve the cast-exports jail; nothing was collected.',
            );
        }

        $protected = $this->protectedNames();

        $deleted = 0;
        $deletedNames = [];
        $skippedProtected = 0;
        $skippedNotDue = 0;
        $deletionFailures = 0;
        $warnings = [];

        foreach ($this->store->listZipArtifacts() as $artifact) {
            if (isset($protected[$artifact->name])) {
                ++$skippedProtected;
                continue;
            }

            if (!$policy->isExpired($artifact->modifiedAt, $now)) {
                ++$skippedNotDue;
                continue;
            }

            if (!$this->store->delete($artifact->path)) {
                ++$deletionFailures;
                $warnings[] = sprintf(
                    'Artifact retention refused to delete "%s".',
                    $artifact->name,
                );
                continue;
            }

            ++$deleted;
            $deletedNames[] = $artifact->name;
        }

        $protectedNames = array_keys($protected);
        sort($protectedNames);

        return RetentionSummary::ran(
            now: $now,
            policy: $policy,
            scanned: $deleted + $skippedProtected + $skippedNotDue,
            deleted: $deleted,
            deletedNames: $deletedNames,
            protected: $protectedNames,
            skippedProtected: $skippedProtected,
            skippedNotDue: $skippedNotDue,
            deletionFailures: $deletionFailures,
            warnings: $warnings,
        );
    }

    /**
     * The set of artifact names (basenames) a sweep must never collect: the
     * latest run's zip plus every non-terminal/resumable run's zip.
     *
     * @return array<string, true>
     */
    private function protectedNames(): array
    {
        $protected = [];
        $latest = null;

        foreach ($this->runs->list() as $run) {
            $zip = $this->runZipName($run);
            if ($zip !== null && (!$run->isTerminal() || $run->lastUploadIdentifier !== null)) {
                $protected[$zip] = true;
            }

            if ($latest === null || $run->updatedAt >= $latest->updatedAt) {
                $latest = $run;
            }
        }

        $latestZip = $this->runZipName($latest);
        if ($latestZip !== null) {
            $protected[$latestZip] = true;
        }

        return $protected;
    }

    private function runZipName(?ExportRun $run): ?string
    {
        if ($run === null) {
            return null;
        }

        $zipPath = $run->pack?->zipPath;
        if ($zipPath === null || $zipPath === '') {
            return null;
        }

        return basename($zipPath);
    }
}
