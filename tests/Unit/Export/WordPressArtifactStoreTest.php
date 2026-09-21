<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\ArtifactFile;
use LumeWeb\Cast\Export\WordPressArtifactStore;
use PHPUnit\Framework\TestCase;

/**
 * The WordPress {@see ArtifactStore} adapter: enumerates and jail-checked
 * deletes the per-run artifact ZIPs under the uploads-root `cast-exports`
 * sibling. The cast-exports directory realpath is the deletion jail: nothing
 * outside it (and nothing that is not a `*.zip` — including the shared
 * `manifest.json`) may ever be removed.
 */
final class WordPressArtifactStoreTest extends TestCase
{
    private string $uploads;

    private string $jail;

    protected function setUp(): void
    {
        $this->uploads = sys_get_temp_dir() . '/cast-artifact-store-' . bin2hex(random_bytes(6));
        mkdir($this->uploads, 0777, true);
        $this->jail = $this->uploads . '/cast-exports';
        mkdir($this->jail, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->uploads);
    }

    public function testJailRootResolvesTheCastExportsRealpath(): void
    {
        $store = new WordPressArtifactStore($this->uploads);

        self::assertSame(realpath($this->jail), $store->jailRoot());
    }

    public function testJailRootIsNullWhenTheExportsDirectoryDoesNotExist(): void
    {
        $this->removeTree($this->jail);

        self::assertNull((new WordPressArtifactStore($this->uploads))->jailRoot());
        self::assertSame([], (new WordPressArtifactStore($this->uploads))->listZipArtifacts());
    }

    public function testListZipArtifactsEnumatesZipFilesWithRealpathAndMtimeSortedByName(): void
    {
        $old = $this->jail . '/run-old.zip';
        $new = $this->jail . '/run-new.zip';
        file_put_contents($old, 'OO');
        file_put_contents($new, 'NN');
        touch($old, 1_000_000);
        touch($new, 2_000_000_000);

        $artifacts = (new WordPressArtifactStore($this->uploads))->listZipArtifacts();

        self::assertSame(['run-new.zip', 'run-old.zip'], array_column($artifacts, 'name'));
        self::assertSame(realpath($new), $artifacts[0]->path);
        self::assertSame(2_000_000_000, $artifacts[0]->modifiedAt);
        self::assertSame(1_000_000, $artifacts[1]->modifiedAt);
        self::assertContainsOnlyInstancesOf(ArtifactFile::class, $artifacts);
    }

    public function testListZipArtifactsNeverIncludesTheSharedManifestOrNonZipFiles(): void
    {
        file_put_contents($this->jail . '/manifest.json', '{}');
        file_put_contents($this->jail . '/notes.txt', 'nope');
        file_put_contents($this->jail . '/run-a.zip', 'AA');
        mkdir($this->jail . '/subdir');

        $names = array_column((new WordPressArtifactStore($this->uploads))->listZipArtifacts(), 'name');

        self::assertSame(['run-a.zip'], $names);
    }

    public function testListZipArtifactsSkipsASymlinkThatEscapesTheJail(): void
    {
        $outside = $this->uploads . '/outside.zip';
        file_put_contents($outside, 'OUT');
        symlink($outside, $this->jail . '/escaped.zip');

        $names = array_column((new WordPressArtifactStore($this->uploads))->listZipArtifacts(), 'name');

        // The symlink resolves outside the jail, so it must never surface as a
        // collectable artifact — an enumeration must be as jail-safe as delete.
        self::assertSame([], $names);
    }

    public function testDeleteRemovesAZipInsideTheJail(): void
    {
        $zip = $this->jail . '/run-1.zip';
        file_put_contents($zip, 'ZIP');

        $store = new WordPressArtifactStore($this->uploads);

        self::assertTrue($store->delete($zip));
        self::assertFileDoesNotExist($zip);
    }

    public function testDeleteRefusesTheSharedManifestJson(): void
    {
        $manifest = $this->jail . '/manifest.json';
        file_put_contents($manifest, '{}');

        $store = new WordPressArtifactStore($this->uploads);

        self::assertFalse($store->delete($manifest));
        self::assertFileExists($manifest);
    }

    public function testDeleteRefusesAnyNonZipEntry(): void
    {
        $notes = $this->jail . '/notes.txt';
        file_put_contents($notes, 'nope');

        $store = new WordPressArtifactStore($this->uploads);

        self::assertFalse($store->delete($notes));
        self::assertFileExists($notes);
    }

    public function testDeleteRefusesAZipOutsideTheJail(): void
    {
        $outside = $this->uploads . '/outside.zip';
        file_put_contents($outside, 'OUT');

        // The realpath of the delete target is a sibling of the jail, not
        // inside it — a traversal/plant must never be followed.
        self::assertFalse((new WordPressArtifactStore($this->uploads))->delete($outside));
        self::assertFileExists($outside);
    }

    public function testDeleteRefusesASymlinkWhoseTargetEscapesTheJail(): void
    {
        $target = $this->uploads . '/target.zip';
        file_put_contents($target, 'SECRET');
        $link = $this->jail . '/evil.zip';
        symlink($target, $link);

        $store = new WordPressArtifactStore($this->uploads);

        // realpath($link) resolves to the outside target, so the jail check
        // refuses the deletion and the outside file survives untouched.
        self::assertFalse($store->delete($link));
        self::assertFileExists($target);
        self::assertFileExists($link);
    }

    public function testDeleteReturnsFalseForAMissingFile(): void
    {
        self::assertFalse((new WordPressArtifactStore($this->uploads))->delete($this->jail . '/ghost.zip'));
    }

    public function testDeleteReturnsFalseWhenTheJailDoesNotExist(): void
    {
        $this->removeTree($this->jail);

        self::assertFalse((new WordPressArtifactStore($this->uploads))->delete($this->uploads . '/cast-exports/run.zip'));
    }

    public function testStoreReadsTheUploadsRootFromWordPressWhenNotInjected(): void
    {
        $GLOBALS['lumeweb_cast_upload_dir'] = [
            'basedir' => $this->uploads,
            'error' => false,
        ];
        file_put_contents($this->jail . '/run-wp.zip', 'WP');

        $store = new WordPressArtifactStore();

        self::assertSame(realpath($this->jail), $store->jailRoot());
        self::assertSame(['run-wp.zip'], array_column($store->listZipArtifacts(), 'name'));

        unset($GLOBALS['lumeweb_cast_upload_dir']);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir) && !is_link($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->removeTree($path);
            }
        }

        @rmdir($dir);
    }
}
