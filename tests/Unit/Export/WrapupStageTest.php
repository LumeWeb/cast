<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\PackResult;
use LumeWeb\Cast\Export\PackStatus;
use LumeWeb\Cast\Export\PipelineStageKey;
use LumeWeb\Cast\Export\PipelineState;
use LumeWeb\Cast\Export\ProbeResult;
use LumeWeb\Cast\Export\SetupResult;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\WrapupResult;
use LumeWeb\Cast\Export\WrapupStage;
use LumeWeb\Cast\Export\ZipPackager;
use PHPUnit\Framework\TestCase;

/**
 * The wrap-up stage: after pack, validates the artifact evidence (ZIP
 * exists, minimum 22 bytes, opens, manifest exists, expected entry count, the
 * tolerated empty artifact) and then jail-checked recursively deletes the
 * jailed work directory — on success and on integrity failure alike — while
 * always preserving the ZIP + manifest for retention. Records the outcome into
 * {@see PipelineState::$wrapup}. One bounded tick; done('') at the wrap-up
 * fixed point.
 */
final class WrapupStageTest extends TestCase
{
    private const ORIGIN = 'https://example.test/';
    private const RUN_ID = 'run-123';

    private string $root;
    private string $uploads;
    private string $workDir;
    private string $artifactDir;
    private PipelineState $state;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cast-wrapup-stage-' . bin2hex(random_bytes(6));
        $this->uploads = $this->root . '/uploads';
        mkdir($this->uploads, 0777, true);
        $this->workDir = $this->uploads . '/cast-work/' . self::RUN_ID;
        mkdir($this->workDir, 0777, true);
        $this->artifactDir = $this->uploads . '/cast-exports';
        $this->state = $this->wrapupableState();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testPipelineStateDeclaresTheWrapupSlot(): void
    {
        $reflection = new \ReflectionClass(PipelineState::class);

        self::assertTrue($reflection->hasProperty('wrapup'));
        self::assertNull($reflection->getProperty('wrapup')->getDefaultValue());
    }

    public function testKeyIsWrapup(): void
    {
        self::assertSame(PipelineStageKey::Wrapup, $this->stage()->key());
    }

    public function testRequiresSuccessfulProbeBeforeWrapup(): void
    {
        $result = $this->stage(new PipelineState())->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('probe', strtolower($result->failure));
    }

    public function testRequiresSuccessfulSetupBeforeWrapup(): void
    {
        $state = $this->wrapupableState();
        $state->setup = null;

        $result = $this->stage($state)->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('setup', strtolower($result->failure));
    }

    public function testRequiresSuccessfulPackBeforeWrapup(): void
    {
        $state = $this->wrapupableState();

        $result = $this->stage($state)->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('pack', strtolower($result->failure));
        self::assertDirectoryExists($this->workDir);
    }

    public function testDeletesWorkDirectoryAndPreservesArtifactOnCompletion(): void
    {
        mkdir($this->workDir . '/about', 0777, true);
        file_put_contents($this->workDir . '/index.html', $this->pad('<html><body>home</body></html>'));
        file_put_contents($this->workDir . '/about/index.html', $this->pad('<html><body>about</body></html>'));
        $pack = $this->packTree();

        $result = $this->stage()->execute('');

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame('', $result->cursor);
        self::assertDirectoryDoesNotExist($this->workDir);
        self::assertFileExists($pack->zipPath);
        self::assertNotNull($pack->manifestPath);
        self::assertFileExists($pack->manifestPath);

        $wrapup = $this->state->wrapup;
        self::assertInstanceOf(WrapupResult::class, $wrapup);
        self::assertTrue($wrapup->success);
        self::assertTrue($wrapup->workDirDeleted);
        self::assertFalse($wrapup->hasIntegrityFailure());
    }

    public function testEmptyArtifactIsAcceptedAndCleanedUp(): void
    {
        $pack = $this->packTree();

        $result = $this->stage()->execute('');

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertDirectoryDoesNotExist($this->workDir);
        self::assertSame(22, filesize($pack->zipPath));

        $wrapup = $this->state->wrapup;
        self::assertInstanceOf(WrapupResult::class, $wrapup);
        self::assertTrue($wrapup->success);
        self::assertTrue($wrapup->workDirDeleted);
    }

    public function testFailsWhenArtifactZipIsMissingButStillDeletesWorkDirectory(): void
    {
        file_put_contents($this->workDir . '/index.html', $this->pad('<html><body>home</body></html>'));
        $pack = $this->packTree();
        unlink($pack->zipPath);

        $result = $this->stage()->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('integrity', strtolower($result->failure));
        self::assertStringContainsString('zip', strtolower($result->failure));
        self::assertDirectoryDoesNotExist($this->workDir);

        $wrapup = $this->state->wrapup;
        self::assertInstanceOf(WrapupResult::class, $wrapup);
        self::assertFalse($wrapup->success);
        self::assertTrue($wrapup->workDirDeleted);
        self::assertTrue($wrapup->hasIntegrityFailure());
    }

    public function testFailsWhenArtifactZipIsBelowMinimumSize(): void
    {
        file_put_contents($this->workDir . '/index.html', $this->pad('<html><body>home</body></html>'));
        $pack = $this->packTree();
        file_put_contents($pack->zipPath, 'X');

        $result = $this->stage()->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('22-byte', strtolower($result->failure));
        self::assertDirectoryDoesNotExist($this->workDir);
        // The undersized evidence is preserved for inspection.
        self::assertSame(1, filesize($pack->zipPath));
    }

    public function testFailsWhenManifestIsMissing(): void
    {
        file_put_contents($this->workDir . '/index.html', $this->pad('<html><body>home</body></html>'));
        $pack = $this->packTree();
        self::assertNotNull($pack->manifestPath);
        unlink($pack->manifestPath);

        $result = $this->stage()->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('manifest', strtolower($result->failure));
        self::assertDirectoryDoesNotExist($this->workDir);
        self::assertFileExists($pack->zipPath);
    }

    public function testFailsWhenZipEntryCountMismatch(): void
    {
        file_put_contents($this->workDir . '/index.html', $this->pad('<html><body>home</body></html>'));
        $pack = $this->packTree();
        self::assertSame(1, $pack->filesAdded);

        // Make the artifact a valid zip with an extra bogus entry.
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($pack->zipPath));
        $zip->addFromString('rogue.txt', 'extra');
        $zip->close();

        $result = $this->stage()->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('entry count', strtolower($result->failure));
        self::assertDirectoryDoesNotExist($this->workDir);
    }

    public function testPropagatesPackWarningsOnCompletion(): void
    {
        file_put_contents($this->workDir . '/index.html', $this->pad('<html><body>home</body></html>'));
        $pack = (new ZipPackager())->pack(
            $this->workDir,
            $this->artifactDir . '/' . self::RUN_ID . '.zip',
            [],
            ['Skipped leak.txt: resolved outside the work-directory jail'],
        );
        $this->state->pack = $pack;
        self::assertSame(PackStatus::CompletedWithWarnings, $pack->status);

        $result = $this->stage()->execute('');

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(['Skipped leak.txt: resolved outside the work-directory jail'], $result->warnings);
        self::assertDirectoryDoesNotExist($this->workDir);

        $wrapup = $this->state->wrapup;
        self::assertInstanceOf(WrapupResult::class, $wrapup);
        self::assertSame(['Skipped leak.txt: resolved outside the work-directory jail'], $wrapup->warnings);
    }

    private function wrapupableState(): PipelineState
    {
        $state = new PipelineState();
        $origin = Origin::fromUrl((new UrlCanonicalizer())->canonicalize(self::ORIGIN));
        $state->probe = new ProbeResult($origin, self::ORIGIN, 2048, 5);
        $state->setup = new SetupResult($this->workDir);

        return $state;
    }

    private function stage(?PipelineState $state = null): WrapupStage
    {
        return new WrapupStage($this->environment(), $state ?? $this->state);
    }

    private function environment(): FakeWrapupEnvironment
    {
        return new FakeWrapupEnvironment($this->uploads);
    }

    /**
     * Pack the current work directory into the artifact directory and record
     * the resulting {@see PackResult} on the shared state.
     */
    private function packTree(): PackResult
    {
        $pack = (new ZipPackager())->pack(
            $this->workDir,
            $this->artifactDir . '/' . self::RUN_ID . '.zip',
            ['run_id' => self::RUN_ID, 'origin' => 'https://example.test'],
        );
        $this->state->pack = $pack;

        return $pack;
    }

    public function testNeverFollowsASymlinkEscapePlantedInsideTheWorkDirectory(): void
    {
        $outside = $this->root . '/outside';
        mkdir($outside, 0777, true);
        file_put_contents($outside . '/secret.txt', 'secret');
        file_put_contents($this->workDir . '/index.html', $this->pad('<html><body>home</body></html>'));
        $this->packTree();
        mkdir($this->workDir . '/sub', 0777, true);
        symlink($outside, $this->workDir . '/sub/leak');

        $result = $this->stage()->execute('');

        // The planted escape must never delete the outside target: only the
        // link entry itself is removed, so the outside directory survives.
        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertDirectoryDoesNotExist($this->workDir);
        self::assertDirectoryExists($outside);
        self::assertFileExists($outside . '/secret.txt');
    }

    public function testRefusesToDeleteWorkDirectoryThatIsASymlinkOutOfTheJail(): void
    {
        // The real pipeline stores the resolved work dir, so a symlinked work
        // directory here simulates post-setup tampering.
        $outside = $this->root . '/outside';
        mkdir($outside, 0777, true);
        file_put_contents($outside . '/secret.txt', 'secret');
        $this->removeTree($this->workDir);
        symlink($outside, $this->workDir);
        $pack = $this->packTree();

        $result = $this->stage()->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('jail', strtolower($result->failure));
        self::assertDirectoryExists($outside);
        self::assertFileExists($outside . '/secret.txt');

        $wrapup = $this->state->wrapup;
        self::assertInstanceOf(WrapupResult::class, $wrapup);
        self::assertFalse($wrapup->success);
        self::assertFalse($wrapup->workDirDeleted);
        self::assertNotNull($wrapup->deletionFailure);
        self::assertStringContainsString('jail', strtolower($wrapup->deletionFailure));
    }

    public function testRefusesToDeleteAWorkDirectoryThatEscapesTheUploadsJail(): void
    {
        $outside = $this->root . '/outside';
        mkdir($outside, 0777, true);
        file_put_contents($outside . '/index.html', $this->pad('<html><body>home</body></html>'));
        $state = $this->wrapupableState();
        $state->setup = new SetupResult($outside);
        $state->pack = $this->packTree();

        $result = $this->stage($state)->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('jail', strtolower($result->failure));
        self::assertDirectoryExists($outside);
        self::assertFileExists($outside . '/index.html');
    }

    public function testFailedPackResultStillDeletesTheWorkDirectoryAndFails(): void
    {
        file_put_contents($this->workDir . '/index.html', $this->pad('<html><body>home</body></html>'));
        $this->state->pack = PackResult::failed($this->artifactDir . '/' . self::RUN_ID . '.zip');

        $result = $this->stage()->execute('');

        self::assertNotNull($result->failure);
        self::assertDirectoryDoesNotExist($this->workDir);
        self::assertFileDoesNotExist($this->artifactDir . '/' . self::RUN_ID . '.zip');

        $wrapup = $this->state->wrapup;
        self::assertInstanceOf(WrapupResult::class, $wrapup);
        self::assertFalse($wrapup->success);
        self::assertTrue($wrapup->workDirDeleted);
        self::assertTrue($wrapup->hasIntegrityFailure());
    }

    private function pad(string $html): string
    {
        return $html . str_repeat(' ', 1500);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (is_link($entry->getPathname())) {
                unlink($entry->getPathname());
                continue;
            }
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
