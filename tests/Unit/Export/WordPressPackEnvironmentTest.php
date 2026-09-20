<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\ArtifactValidationMode;
use LumeWeb\Cast\Export\WordPressPackEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The WordPress {@see PackEnvironment} adapter: resolves the per-run artifact
 * ZIP into the denied `cast-exports` sibling of the WordPress uploads root and
 * reads the pre-pack validation strictness from the `cast_artifact_validation`
 * option (defaulting to the non-blocking warning mode).
 */
final class WordPressPackEnvironmentTest extends TestCase
{
    protected function setUp(): void
    {
        unset($GLOBALS['lumeweb_cast_options'][WordPressPackEnvironment::VALIDATION_MODE_OPTION]);
        $GLOBALS['lumeweb_cast_upload_dir'] = [
            'path' => '/tmp/uploads',
            'url' => 'http://example.test/wp-content/uploads',
            'subdir' => '',
            'basedir' => '/tmp/uploads',
            'baseurl' => 'http://example.test/wp-content/uploads',
            'error' => false,
        ];
    }

    public function testArtifactPathIsTheRunZipUnderTheCastExportsSiblingDirectory(): void
    {
        self::assertSame(
            '/tmp/uploads/cast-exports/run-abc.zip',
            (new WordPressPackEnvironment())->artifactPath('run-abc'),
        );
    }

    public function testArtifactPathIsDistinctPerRunId(): void
    {
        $env = new WordPressPackEnvironment();

        self::assertNotSame($env->artifactPath('run-1'), $env->artifactPath('run-2'));
        self::assertStringEndsWith('/cast-exports/run-2.zip', $env->artifactPath('run-2'));
        self::assertStringEndsWith('/cast-exports/run-1.zip', $env->artifactPath('run-1'));
    }

    public function testValidationModeDefaultsToWarningWhenUnconfigured(): void
    {
        unset($GLOBALS['lumeweb_cast_options'][WordPressPackEnvironment::VALIDATION_MODE_OPTION]);

        self::assertSame(ArtifactValidationMode::Warning, (new WordPressPackEnvironment())->validationMode());
    }

    public function testValidationModeReadsTheConfiguredOption(): void
    {
        $GLOBALS['lumeweb_cast_options'][WordPressPackEnvironment::VALIDATION_MODE_OPTION] = 'strict';
        self::assertSame(ArtifactValidationMode::Strict, (new WordPressPackEnvironment())->validationMode());

        $GLOBALS['lumeweb_cast_options'][WordPressPackEnvironment::VALIDATION_MODE_OPTION] = 'warning';
        self::assertSame(ArtifactValidationMode::Warning, (new WordPressPackEnvironment())->validationMode());
    }

    public function testUnknownValidationModeFallsBackToWarning(): void
    {
        $GLOBALS['lumeweb_cast_options'][WordPressPackEnvironment::VALIDATION_MODE_OPTION] = 'bogus';

        // A corrupt option must never silently escalate to a strict check that
        // blocks packing: the safe default is the non-blocking warning mode.
        self::assertSame(ArtifactValidationMode::Warning, (new WordPressPackEnvironment())->validationMode());
    }

    public function testArtifactPathThrowsWhenWordPressHasNoUploadsDirectory(): void
    {
        $GLOBALS['lumeweb_cast_upload_dir'] = ['error' => true];

        $this->expectException(\RuntimeException::class);
        (new WordPressPackEnvironment())->artifactPath('run-1');
    }
}
