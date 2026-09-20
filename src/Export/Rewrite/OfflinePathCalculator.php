<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

/**
 * Offline `../` depth math between two deterministic output files.
 *
 * The page's own output file (e.g. 2013/01/11/page-a/index.html) is the
 * reference; the target output file is the destination after OutputPathResolver
 * has already applied its rules (pages -> {path}/index.html, query pages ->
 * __qs/{hash}/index.html, assets keep their WordPress path). The result is a
 * './' based relative path that is correct no matter where the ZIP is unzipped,
 * matching Simply Static's create_offline_path.
 */
final class OfflinePathCalculator
{
    public function relativeTo(string $fromOutputFile, string $toOutputFile): string
    {
        $fromDir = $this->directorySegments(dirname($fromOutputFile));
        $toDir = $this->directorySegments(dirname($toOutputFile));

        $common = 0;
        $limit = min(count($fromDir), count($toDir));
        while ($common < $limit && $fromDir[$common] === $toDir[$common]) {
            ++$common;
        }

        $up = count($fromDir) - $common;
        $down = array_slice($toDir, $common);
        $down[] = basename($toOutputFile);

        $remainder = implode('/', array_filter($down, static fn (string $part): bool => $part !== ''));

        $base = '.' . str_repeat('/..', $up);

        if ($remainder === '') {
            return $base;
        }

        return $base . '/' . $remainder;
    }

    /**
     * @return list<string>
     */
    private function directorySegments(string $directory): array
    {
        $directory = trim(str_replace('\\', '/', $directory), '/');
        if ($directory === '' || $directory === '.') {
            return [];
        }

        return explode('/', $directory);
    }
}
