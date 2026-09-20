<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\WordPressWrapupEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The WordPress {@see WrapupEnvironment} adapter: resolves the uploads jail
 * root the wrap-up stage deletes inside of from the WordPress uploads
 * directory, exactly like the setup environment that created the jailed work
 * directory. An absent uploads root fails loudly rather than deleting outside
 * a known jail.
 */
final class WordPressWrapupEnvironmentTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_upload_dir'] = [
            'path' => '/tmp/uploads',
            'url' => 'http://example.test/wp-content/uploads',
            'subdir' => '',
            'basedir' => '/tmp/uploads',
            'baseurl' => 'http://example.test/wp-content/uploads',
            'error' => false,
        ];
    }

    public function testUploadsDirectoryResolvesToTheWordPressUploadsBaseDir(): void
    {
        self::assertSame('/tmp/uploads', (new WordPressWrapupEnvironment())->uploadsDirectory());
    }

    public function testUploadsDirectoryThrowsWhenWordPressHasNoUploadsDirectory(): void
    {
        $GLOBALS['lumeweb_cast_upload_dir'] = ['error' => true];

        $this->expectException(\RuntimeException::class);
        (new WordPressWrapupEnvironment())->uploadsDirectory();
    }
}
