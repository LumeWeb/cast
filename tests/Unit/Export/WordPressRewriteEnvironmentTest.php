<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use InvalidArgumentException;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\Rewrite\RewriteService;
use LumeWeb\Cast\Export\WordPressRewriteEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The WordPress {@see RewriteEnvironment} adapter: builds a real per-run
 * {@see \LumeWeb\Cast\Export\Rewrite\RewriteService}, reads captured bodies
 * safely from inside the run's jailed work directory (never following a
 * traversal, encoded traversal, absolute escape or symlink out of the jail),
 * and writes rewritten bodies back atomically into the same directory through
 * the existing {@see \LumeWeb\Cast\Export\LocalOutputFileSystem} convention.
 */
final class WordPressRewriteEnvironmentTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/cast-rew-env-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workDir);
    }

    public function testRewriteServiceBuildsTheRealPerRunService(): void
    {
        $service = $this->environment()->rewriteService(
            Origin::fromParts('https', 'blog.example.test', null),
            $this->workDir,
        );

        self::assertInstanceOf(RewriteService::class, $service);
    }

    public function testReadBodyReadsCapturedBodyFromTheWorkDirectory(): void
    {
        $dir = $this->workDir . DIRECTORY_SEPARATOR . 'about';
        mkdir($dir, 0777, true);
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'index.html', '<html><body>captured</body></html>');

        self::assertSame(
            '<html><body>captured</body></html>',
            $this->environment()->readBody('about/index.html'),
        );
    }

    public function testReadBodyThrowsForMissingBody(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->environment()->readBody('missing/index.html');
    }

    public function testReadBodyRejectsDotSegmentTraversal(): void
    {
        $outside = sys_get_temp_dir() . '/cast-rew-outside-' . bin2hex(random_bytes(6));
        file_put_contents($outside, 'OUTSIDE-SECRET');

        try {
            $this->expectException(\RuntimeException::class);
            $this->environment()->readBody('../' . basename($outside));
        } finally {
            // A traversal must never read a file outside the jail.
            @unlink($outside);
        }
    }

    public function testReadBodyRejectsNestedDotSegmentTraversal(): void
    {
        $outside = sys_get_temp_dir() . '/cast-rew-outside-' . bin2hex(random_bytes(6));
        file_put_contents($outside, 'OUTSIDE-SECRET');

        try {
            $this->expectException(\RuntimeException::class);
            $this->environment()->readBody('assets/../../' . basename($outside));
        } finally {
            @unlink($outside);
        }
    }

    public function testReadBodyRejectsEncodedTraversal(): void
    {
        $outside = sys_get_temp_dir() . '/cast-rew-outside-' . bin2hex(random_bytes(6));
        file_put_contents($outside, 'OUTSIDE-SECRET');

        try {
            $this->expectException(\RuntimeException::class);
            $this->environment()->readBody('%2e%2e/' . basename($outside));
        } finally {
            @unlink($outside);
        }
    }

    public function testReadBodyCannotEscapeViaAnAbsolutePath(): void
    {
        // /etc/hostname exists on the host, but the jailed reader must never
        // serve it: an absolute path is rooted back inside the jail where it
        // does not exist.
        $this->expectException(\RuntimeException::class);

        $this->environment()->readBody('/etc/hostname');
    }

    public function testReadBodyCannotEscapeViaASymlink(): void
    {
        $outside = sys_get_temp_dir() . '/cast-rew-outside-' . bin2hex(random_bytes(6));
        file_put_contents($outside, 'OUTSIDE-SECRET');
        @symlink($outside, $this->workDir . DIRECTORY_SEPARATOR . 'link.txt');

        try {
            $this->expectException(\RuntimeException::class);
            $this->environment()->readBody('link.txt');
        } finally {
            @unlink($outside);
        }
    }

    public function testWriteBodyPersistsRewrittenContentAtomicallyInsideTheWorkDirectory(): void
    {
        $this->environment()->writeBody('about/index.html', 'REWRITTEN-ONE');

        $target = $this->workDir . DIRECTORY_SEPARATOR . 'about' . DIRECTORY_SEPARATOR . 'index.html';
        self::assertFileExists($target);
        self::assertSame('REWRITTEN-ONE', file_get_contents($target));

        // A second write replaces the first (atomic commit over the same path).
        $this->environment()->writeBody('about/index.html', 'REWRITTEN-TWO');
        self::assertSame('REWRITTEN-TWO', file_get_contents($target));
    }

    public function testWriteBodyRejectsDotSegmentTraversal(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->environment()->writeBody('../escape.txt', 'NOPE');
    }

    public function testWriteBodyRejectsNestedDotSegmentTraversal(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->environment()->writeBody('assets/../../escape.txt', 'NOPE');
    }

    private function environment(): WordPressRewriteEnvironment
    {
        return new WordPressRewriteEnvironment($this->workDir);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file->isLink() || $file->isFile()) {
                @unlink($file->getPathname());
            } elseif ($file->isDir()) {
                @rmdir($file->getPathname());
            }
        }
        @unlink($dir);
    }
}
