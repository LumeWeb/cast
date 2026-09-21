<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use InvalidArgumentException;
use LumeWeb\Cast\Export\PackResult;
use LumeWeb\Cast\Export\PackStatus;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PackResultTest extends TestCase
{
    public function testCompletedPackResultExposesCountsAndPaths(): void
    {
        $result = new PackResult(
            PackStatus::Completed,
            42,
            0,
            12345,
            '/tmp/exports/run-1.zip',
            '/tmp/exports/manifest.json',
        );

        self::assertSame(PackStatus::Completed, $result->status);
        self::assertSame(42, $result->filesAdded);
        self::assertSame(0, $result->filesSkipped);
        self::assertSame(12345, $result->bytesWritten);
        self::assertSame('/tmp/exports/run-1.zip', $result->zipPath);
        self::assertSame('/tmp/exports/manifest.json', $result->manifestPath);
        self::assertFalse($result->hasWarnings());
    }

    public function testWarningsMarkResultAsCompletedWithWarnings(): void
    {
        $result = new PackResult(
            PackStatus::CompletedWithWarnings,
            10,
            2,
            999,
            '/tmp/exports/run-2.zip',
            '/tmp/exports/manifest.json',
            ['Skipped leak.txt: resolved outside the work directory jail'],
        );

        self::assertSame(PackStatus::CompletedWithWarnings, $result->status);
        self::assertSame(10, $result->filesAdded);
        self::assertSame(2, $result->filesSkipped);
        self::assertTrue($result->hasWarnings());
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('leak.txt', $result->warnings[0]);
    }

    public function testFailedFactoryProducesFailedStatus(): void
    {
        $result = PackResult::failed('/tmp/exports/run-3.zip', ['pending_items']);

        self::assertSame(PackStatus::Failed, $result->status);
        self::assertSame(0, $result->filesAdded);
        self::assertSame(0, $result->filesSkipped);
        self::assertSame(0, $result->bytesWritten);
        self::assertSame(['pending_items'], $result->warnings);
        self::assertSame('/tmp/exports/run-3.zip', $result->zipPath);
        self::assertNull($result->manifestPath);
    }

    public function testFailedFactoryExposesMissingZipExtensionProblem(): void
    {
        $result = PackResult::failed('/tmp/exports/run-4.zip', ['PHP zip extension required']);

        self::assertSame(PackStatus::Failed, $result->status);
        self::assertSame(['PHP zip extension required'], $result->warnings);
        self::assertNull($result->manifestPath);
    }

    public function testCompletedPackResultRoundTripsThroughItsPersistedShape(): void
    {
        $result = new PackResult(
            PackStatus::Completed,
            42,
            0,
            12345,
            '/tmp/exports/run-1.zip',
            '/tmp/exports/manifest.json',
        );

        $restored = PackResult::fromArray($result->toArray());

        self::assertSame(PackStatus::Completed, $restored->status);
        self::assertSame(42, $restored->filesAdded);
        self::assertSame(0, $restored->filesSkipped);
        self::assertSame(12345, $restored->bytesWritten);
        self::assertSame('/tmp/exports/run-1.zip', $restored->zipPath);
        self::assertSame('/tmp/exports/manifest.json', $restored->manifestPath);
        self::assertFalse($restored->hasWarnings());
        // round-trip is stable: persisting the restored result changes nothing.
        self::assertSame($result->toArray(), $restored->toArray());
    }

    public function testWarnedAndFailedResultsRetainWarningsAndNullManifestThroughRoundTrip(): void
    {
        $warned = new PackResult(
            PackStatus::CompletedWithWarnings,
            10,
            2,
            999,
            '/tmp/exports/run-2.zip',
            '/tmp/exports/manifest.json',
            ['Skipped leak.txt: resolved outside the work directory jail'],
        );
        $restored = PackResult::fromArray($warned->toArray());
        self::assertSame(PackStatus::CompletedWithWarnings, $restored->status);
        self::assertSame(['Skipped leak.txt: resolved outside the work directory jail'], $restored->warnings);
        self::assertTrue($restored->hasWarnings());
        self::assertSame($warned->toArray(), $restored->toArray());

        $failed = PackResult::failed('/tmp/exports/run-3.zip', ['PHP zip extension required']);
        $restoredFailed = PackResult::fromArray($failed->toArray());
        self::assertSame(PackStatus::Failed, $restoredFailed->status);
        self::assertSame(0, $restoredFailed->filesAdded);
        self::assertNull($restoredFailed->manifestPath);
        self::assertSame(['PHP zip extension required'], $restoredFailed->warnings);
        self::assertSame($failed->toArray(), $restoredFailed->toArray());
    }

    public function testFromArrayRejectsDataThatIsNotAnArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PackResult::fromArray('nope');
    }

    public function testFromArrayRejectsMalformedPersistedData(): void
    {
        $base = (new PackResult(
            PackStatus::Completed,
            1,
            0,
            2,
            '/tmp/exports/run-5.zip',
            '/tmp/exports/manifest.json',
        ))->toArray();

        // Unknown pack status is refused, never silently reinterpreted.
        $badStatus = $base;
        $badStatus['status'] = 'bogus';
        $this->expectException(InvalidArgumentException::class);
        PackResult::fromArray($badStatus);
    }

    #[DataProvider('malformedPersistedProvider')]
    public function testFromArrayRejectsMalformedPersistedFields(string $key, mixed $value): void
    {
        $base = (new PackResult(
            PackStatus::Completed,
            1,
            0,
            2,
            '/tmp/exports/run-6.zip',
            '/tmp/exports/manifest.json',
        ))->toArray();
        $base[$key] = $value;

        $this->expectException(InvalidArgumentException::class);
        PackResult::fromArray($base);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function malformedPersistedProvider(): iterable
    {
        yield 'files_added is not an int' => ['files_added', 'nope'];
        yield 'files_skipped is negative' => ['files_skipped', -1];
        yield 'bytes_written is not an int' => ['bytes_written', []];
        yield 'zip_path is empty' => ['zip_path', ''];
        yield 'manifest_path is not a string or null' => ['manifest_path', 12];
        yield 'warnings is not a list of strings' => ['warnings', ['ok', 12]];
    }
}
