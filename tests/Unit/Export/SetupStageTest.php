<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\PipelineStageKey;
use LumeWeb\Cast\Export\PipelineState;
use LumeWeb\Cast\Export\SetupEnvironment;
use LumeWeb\Cast\Export\SetupStage;
use PHPUnit\Framework\TestCase;

final class SetupStageTest extends TestCase
{
    private string $uploads;

    protected function setUp(): void
    {
        $this->uploads = sys_get_temp_dir() . '/cast-uploads-' . bin2hex(random_bytes(6));
        mkdir($this->uploads, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->uploads);
    }

    public function testKeyIsSetup(): void
    {
        self::assertSame(PipelineStageKey::Setup, $this->stage()->key());
    }

    public function testPipelineStateDeclaresTheSetupSlot(): void
    {
        $reflection = new \ReflectionClass(PipelineState::class);

        self::assertTrue($reflection->hasProperty('setup'));
        self::assertNull($reflection->getProperty('setup')->getDefaultValue());
    }

    public function testCreatesJailedPerRunDirectoryAndWebDenialFiles(): void
    {
        $state = new PipelineState();
        $result = $this->stage($state)->execute('');

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertNotNull($state->setup);
        self::assertSame(
            realpath($this->uploads . '/cast-work/run-123'),
            $state->setup->workDir,
        );
        self::assertStringStartsWith(realpath($this->uploads) . DIRECTORY_SEPARATOR, $state->setup->workDir);
        self::assertSame("<?php\n// Silence is golden.\n", file_get_contents($state->setup->workDir . '/index.php'));
        self::assertStringContainsString('Require all denied', (string) file_get_contents($state->setup->workDir . '/.htaccess'));
        self::assertStringContainsString('Deny from all', (string) file_get_contents($state->setup->workDir . '/.htaccess'));
    }

    public function testResumeIsIdempotentAndKeepsTheSameWorkDirectory(): void
    {
        $state = new PipelineState();
        $stage = $this->stage($state);

        $first = $stage->execute('');
        $workDir = $state->setup?->workDir;
        self::assertTrue($first->done);
        self::assertNotNull($workDir);

        $second = $stage->execute($first->cursor);

        self::assertTrue($second->done);
        self::assertSame($workDir, $state->setup?->workDir);
        self::assertDirectoryExists($workDir);
        self::assertFileExists($workDir . '/index.php');
        self::assertFileExists($workDir . '/.htaccess');
    }

    public function testRejectsRunIdThatCouldEscapeUploadsJail(): void
    {
        $state = new PipelineState();
        $result = (new SetupStage($this->environment(), $state, '../outside'))->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('run identifier', strtolower($result->failure));
        self::assertNull($state->setup);
        self::assertDirectoryDoesNotExist(dirname($this->uploads) . '/outside');
    }

    public function testRejectsExistingWorkDirectorySymlinkThatEscapesUploads(): void
    {
        $outside = sys_get_temp_dir() . '/cast-outside-' . bin2hex(random_bytes(6));
        mkdir($outside, 0777, true);
        mkdir($this->uploads . '/cast-work', 0777, true);
        symlink($outside, $this->uploads . '/cast-work/run-123');

        try {
            $state = new PipelineState();
            $result = $this->stage($state)->execute('');

            self::assertNotNull($result->failure);
            self::assertStringContainsString('work directory', strtolower($result->failure));
            self::assertNull($state->setup);
            self::assertFileDoesNotExist($outside . '/index.php');
        } finally {
            $this->removeTree($outside);
        }
    }

    public function testReportsMissingUploadsDirectory(): void
    {
        $state = new PipelineState();
        $result = (new SetupStage(
            new class implements SetupEnvironment {
                public function uploadsDirectory(): string
                {
                    return '/path/that/does/not/exist';
                }
            },
            $state,
            'run-123',
        ))->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('uploads', strtolower($result->failure));
        self::assertNull($state->setup);
    }

    private function stage(?PipelineState $state = null): SetupStage
    {
        return new SetupStage($this->environment(), $state ?? new PipelineState(), 'run-123');
    }

    private function environment(): SetupEnvironment
    {
        $uploads = $this->uploads;

        return new class ($uploads) implements SetupEnvironment {
            public function __construct(private readonly string $uploads)
            {
            }

            public function uploadsDirectory(): string
            {
                return $this->uploads;
            }
        };
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
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
