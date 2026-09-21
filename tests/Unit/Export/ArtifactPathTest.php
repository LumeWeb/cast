<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\ArtifactPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArtifactPathTest extends TestCase
{
    #[DataProvider('normalizationCases')]
    public function testNormalizesToForwardSlashRelativePath(string $raw, string $expected): void
    {
        self::assertSame($expected, ArtifactPath::normalize($raw));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function normalizationCases(): array
    {
        return [
            'backslashes fold to slashes' => ['wp-content\\uploads\\a.jpg', 'wp-content/uploads/a.jpg'],
            'leading slash trimmed' => ['/wp-content/a.jpg', 'wp-content/a.jpg'],
            'double slashes collapsed' => ['a//b//c.txt', 'a/b/c.txt'],
            'trailing slash trimmed' => ['a/b/', 'a/b'],
            'leading dot slash trimmed' => ['./a/b.txt', 'a/b.txt'],
            'deep dot segments preserved for later rejection' => ['../a.txt', '../a.txt'],
            'empty stays empty' => ['', ''],
        ];
    }

    #[DataProvider('violationCases')]
    public function testReportsPathViolations(string $raw, ?string $expectedReason): void
    {
        self::assertSame($expectedReason, ArtifactPath::violation($raw));
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function violationCases(): array
    {
        return [
            'safe unchanged path' => ['wp-content/uploads/a.jpg', null],
            'safe index' => ['index.html', null],
            'dot segment rejection' => ['a/../b.txt', 'dot_segment'],
            'encoded traversal still rejected' => ['..\\..\\secret.txt', 'dot_segment'],
            'absolute unix path rejected' => ['/etc/passwd', 'absolute'],
            'absolute windows path rejected' => ['C:\\windows\\x.exe', 'absolute'],
            'nul byte rejected' => ["a\0b.txt", 'nul_byte'],
            'control character rejected' => ["a\x01b.txt", 'control_character'],
            'empty rejected' => ['', 'empty'],
            'leading dot hidden file allowed' => ['.htaccess', null],
            'dot segment inside deep path rejected' => ['uploads/./x.jpg', 'dot_segment'],
        ];
    }

    public function testDetectsDuplicateNormalizedOutputPaths(): void
    {
        $paths = [
            'index.html',
            'wp-content/a.jpg',
            'wp-content\\a.jpg',
            'css/style.css',
            'css//style.css',
            'about/index.html',
        ];

        self::assertSame(['css/style.css', 'wp-content/a.jpg'], ArtifactPath::duplicates($paths));
    }

    public function testNoDuplicatesWhenAllUnique(): void
    {
        self::assertSame([], ArtifactPath::duplicates(['a.txt', 'b.txt', 'c/d.txt']));
    }
}
