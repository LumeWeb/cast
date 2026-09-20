<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\PackResult;
use LumeWeb\Cast\Export\PackStatus;
use LumeWeb\Cast\Export\ZipPackager;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Pack vectors against a real ZipArchive on a temporary filesystem.
 */
final class ZipPackagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cast-pack-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testEmptyWorkDirWritesValidTwentyTwoByteEocdZip(): void
    {
        $workDir = $this->root . '/work';
        mkdir($workDir, 0777, true);
        $zipPath = $this->root . '/exports/run-1.zip';

        $result = (new ZipPackager())->pack($workDir, $zipPath);

        self::assertSame(PackStatus::Completed, $result->status);
        self::assertFileExists($zipPath);

        // The minimal empty ZIP is exactly the 22-byte EOCD record and opens.
        self::assertSame(22, filesize($zipPath));
        $zip = new ZipArchive();
        try {
            self::assertTrue($zip->open($zipPath));
            self::assertSame(0, $zip->numFiles);
        } finally {
            $zip->close();
        }

        // A manifest is still written next to the zip so the run is inspectable.
        self::assertNotNull($result->manifestPath);
        self::assertFileExists($result->manifestPath);
        $manifest = json_decode((string) file_get_contents($result->manifestPath), true);
        self::assertIsArray($manifest);
        self::assertSame(0, $manifest['packed_files']);
        self::assertTrue($manifest['empty_artifact']);
    }

    public function testPacksFilesWithNormalizedRelativeNamesAndExtractsCleanly(): void
    {
        $workDir = $this->root . '/work';
        mkdir($workDir . '/about', 0777, true);
        mkdir($workDir . '/wp-content/uploads', 0777, true);
        file_put_contents($workDir . '/index.html', '<html>root</html>');
        file_put_contents($workDir . '/about/index.html', '<html>about</html>');
        file_put_contents($workDir . '/wp-content/uploads/photo.jpg', 'JPGDATA');
        $zipPath = $this->root . '/exports/run-2.zip';

        $result = (new ZipPackager())->pack($workDir, $zipPath);

        self::assertSame(PackStatus::Completed, $result->status);
        self::assertSame(3, $result->filesAdded);
        self::assertSame(0, $result->filesSkipped);
        self::assertGreaterThan(22, filesize($zipPath));

        $zip = new ZipArchive();
        try {
            self::assertTrue($zip->open($zipPath));
            $names = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $names[] = $zip->getNameIndex($i);
            }
            // Site files are sorted, then the manifest is appended last.
            self::assertSame([
                'about/index.html',
                'index.html',
                'wp-content/uploads/photo.jpg',
                'manifest.json',
            ], $names);
            self::assertSame('<html>about</html>', $zip->getFromName('about/index.html'));
            self::assertSame('JPGDATA', $zip->getFromName('wp-content/uploads/photo.jpg'));
        } finally {
            $zip->close();
        }

        // Extraction round-trips to clean relative files on disk.
        $out = $this->root . '/extract';
        mkdir($out, 0777, true);
        $zip = new ZipArchive();
        try {
            $zip->open($zipPath);
            self::assertTrue($zip->extractTo($out));
        } finally {
            $zip->close();
        }
        self::assertFileExists($out . '/about/index.html');
        self::assertFileExists($out . '/wp-content/uploads/photo.jpg');
    }

    public function testJailEscapeSymlinkIsSkippedWithWarning(): void
    {
        $workDir = $this->root . '/work';
        mkdir($workDir, 0777, true);
        file_put_contents($workDir . '/index.html', '<html>ok</html>');

        $outside = $this->root . '/outside-secret.txt';
        file_put_contents($outside, 'SECRET');
        @symlink($outside, $workDir . '/leak.txt');

        $zipPath = $this->root . '/exports/run-3.zip';

        $result = (new ZipPackager())->pack($workDir, $zipPath);

        self::assertSame(PackStatus::CompletedWithWarnings, $result->status);
        self::assertSame(1, $result->filesAdded);
        self::assertSame(1, $result->filesSkipped);
        self::assertTrue($result->hasWarnings());
        self::assertStringContainsString('leak.txt', $result->warnings[0]);

        $zip = new ZipArchive();
        try {
            self::assertTrue($zip->open($zipPath));
            $names = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $names[] = $zip->getNameIndex($i);
            }
            self::assertNotContains('leak.txt', $names);
            self::assertNotContains('outside-secret.txt', $names);
        } finally {
            $zip->close();
        }
    }

    public function testBackslashNameIsNormalizedToSlashInsideArchive(): void
    {
        $workDir = $this->root . '/work';
        mkdir($workDir, 0777, true);
        // A literal single-byte backslash in a filename on POSIX.
        file_put_contents($workDir . '/evil\\name.txt', 'data');
        $zipPath = $this->root . '/exports/run-4.zip';

        $result = (new ZipPackager())->pack($workDir, $zipPath);

        self::assertSame(PackStatus::Completed, $result->status);
        $zip = new ZipArchive();
        try {
            self::assertTrue($zip->open($zipPath));
            self::assertNotSame(false, $zip->getFromName('evil/name.txt'));
            self::assertSame('data', $zip->getFromName('evil/name.txt'));
        } finally {
            $zip->close();
        }
    }

    public function testManifestIsLastEntryAndMatchesAdjacentFile(): void
    {
        $workDir = $this->root . '/work';
        mkdir($workDir, 0777, true);
        file_put_contents($workDir . '/index.html', '<html>a</html>');
        file_put_contents($workDir . '/style.css', 'body{}');
        $zipPath = $this->root . '/exports/run-5.zip';

        $result = (new ZipPackager())->pack($workDir, $zipPath, [
            'run_id' => 'run-5',
            'origin' => 'https://example.com',
        ]);

        $zip = new ZipArchive();
        try {
            self::assertTrue($zip->open($zipPath));
            $lastName = $zip->getNameIndex($zip->numFiles - 1);
            self::assertSame('manifest.json', $lastName);

            $inside = json_decode((string) $zip->getFromName('manifest.json'), true);
            self::assertIsArray($inside);
            self::assertSame('run-5', $inside['run_id']);
            self::assertSame(2, $inside['packed_files']);
        } finally {
            $zip->close();
        }

        // Adjacent manifest exists next to the zip and matches the in-zip copy.
        self::assertNotNull($result->manifestPath);
        self::assertFileExists($result->manifestPath);
        $outside = json_decode((string) file_get_contents($result->manifestPath), true);
        self::assertIsArray($outside);
        self::assertSame($inside, $outside);
    }

    public function testBatchBoundaryAddsAllFilesAcrossBatches(): void
    {
        $workDir = $this->root . '/work';
        mkdir($workDir, 0777, true);
        for ($i = 0; $i < 5; ++$i) {
            file_put_contents($workDir . sprintf('/file-%d.txt', $i), "content-$i");
        }
        $zipPath = $this->root . '/exports/run-6.zip';

        $result = (new ZipPackager(2))->pack($workDir, $zipPath);

        self::assertSame(PackStatus::Completed, $result->status);
        self::assertSame(5, $result->filesAdded);

        $zip = new ZipArchive();
        try {
            self::assertTrue($zip->open($zipPath));
            self::assertSame(6, $zip->numFiles); // 5 files + manifest
            self::assertSame('content-4', $zip->getFromName('file-4.txt'));
        } finally {
            $zip->close();
        }
    }

    public function testBatchSizeGetterClampsToOneAndMax(): void
    {
        self::assertSame(2500, (new ZipPackager())->batchSize());
        self::assertSame(1, (new ZipPackager(0))->batchSize());
        self::assertSame(10000, (new ZipPackager(99999))->batchSize());
    }

    public function testArtifactPathInsideWorkDirIsRejected(): void
    {
        $workDir = $this->root . '/work';
        mkdir($workDir, 0777, true);
        file_put_contents($workDir . '/index.html', '<html>a</html>');
        $zipPath = $workDir . '/self.zip';

        $result = (new ZipPackager())->pack($workDir, $zipPath);

        self::assertSame(PackStatus::Failed, $result->status);
        self::assertSame(0, $result->filesAdded);
        self::assertStringContainsString('outside', strtolower(implode(' ', $result->warnings)));
    }

    public function testCleanPackReportsCompletedAndBytesWritten(): void
    {
        $workDir = $this->root . '/work';
        mkdir($workDir, 0777, true);
        file_put_contents($workDir . '/index.html', str_repeat('x', 2048));
        $zipPath = $this->root . '/exports/run-8.zip';

        $result = (new ZipPackager())->pack($workDir, $zipPath);

        self::assertSame(PackStatus::Completed, $result->status);
        self::assertSame(1, $result->filesAdded);
        self::assertTrue($result->bytesWritten > 0);
        self::assertSame($zipPath, $result->zipPath);
        self::assertNotNull($result->manifestPath);
        self::assertStringEndsWith('.json', $result->manifestPath);
        // The work dir was not mutated by packing (manifest lives outside it).
        self::assertSame(['index.html'], $this->listFiles($workDir));
    }

    public function testPreExistingWarningsFoldIntoCompletedWithWarnings(): void
    {
        $workDir = $this->root . '/work';
        mkdir($workDir, 0777, true);
        file_put_contents($workDir . '/index.html', '<html>a</html>');
        $zipPath = $this->root . '/exports/run-10.zip';

        $result = (new ZipPackager())->pack(
            $workDir,
            $zipPath,
            [],
            ['leftover_origin: https://example.com is still present in index.html'],
        );

        self::assertSame(PackStatus::CompletedWithWarnings, $result->status);
        self::assertTrue($result->hasWarnings());
        self::assertStringContainsString('leftover_origin', $result->warnings[0]);
        self::assertSame(1, $result->filesAdded);
    }

    public function testMissingWorkDirFails(): void
    {
        $result = (new ZipPackager())->pack($this->root . '/nope', $this->root . '/exports/run-9.zip');

        self::assertSame(PackStatus::Failed, $result->status);
        self::assertSame(0, $result->filesAdded);
    }

    /**
     * @return list<string>
     */
    private function listFiles(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $entry) {
            if ($entry->isFile()) {
                $files[] = str_replace($dir . DIRECTORY_SEPARATOR, '', $entry->getPathname());
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }
}
