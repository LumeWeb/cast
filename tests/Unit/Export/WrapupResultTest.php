<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use InvalidArgumentException;
use LumeWeb\Cast\Export\WrapupResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WrapupResultTest extends TestCase
{
    public function testCompletedFactoryProducesSuccess(): void
    {
        $result = WrapupResult::completed(true, ['pack warning']);

        self::assertTrue($result->success);
        self::assertTrue($result->workDirDeleted);
        self::assertFalse($result->hasIntegrityFailure());
        self::assertSame([], $result->integrityErrors);
        self::assertNull($result->deletionFailure);
        self::assertSame(['pack warning'], $result->warnings);
    }

    public function testCompletedFactoryMayRecordAnUndeletedWorkDirectory(): void
    {
        $result = WrapupResult::completed(false);

        self::assertTrue($result->success);
        self::assertFalse($result->workDirDeleted);
    }

    public function testIntegrityFailureFactoryRecordsErrors(): void
    {
        $result = WrapupResult::integrityFailure(['Artifact ZIP file is missing.'], true);

        self::assertFalse($result->success);
        self::assertTrue($result->workDirDeleted);
        self::assertTrue($result->hasIntegrityFailure());
        self::assertSame(['Artifact ZIP file is missing.'], $result->integrityErrors);
        self::assertNull($result->deletionFailure);
    }

    public function testDeletionFailureFactoryRecordsReason(): void
    {
        $result = WrapupResult::deletionFailure('Wrapup work directory escapes the uploads jail.');

        self::assertFalse($result->success);
        self::assertFalse($result->workDirDeleted);
        self::assertFalse($result->hasIntegrityFailure());
        self::assertSame([], $result->integrityErrors);
        self::assertSame('Wrapup work directory escapes the uploads jail.', $result->deletionFailure);
    }

    public function testCompletedWrapupResultRoundTripsThroughItsPersistedShape(): void
    {
        $result = WrapupResult::completed(true, ['pack warning']);

        $restored = WrapupResult::fromArray($result->toArray());

        self::assertTrue($restored->success);
        self::assertTrue($restored->workDirDeleted);
        self::assertFalse($restored->hasIntegrityFailure());
        self::assertSame([], $restored->integrityErrors);
        self::assertNull($restored->deletionFailure);
        self::assertSame(['pack warning'], $restored->warnings);
        // round-trip is stable: persisting the restored result changes nothing.
        self::assertSame($result->toArray(), $restored->toArray());
    }

    public function testIntegrityAndDeletionFailuresRetainDetailsAndWarningsThroughRoundTrip(): void
    {
        $integrity = WrapupResult::integrityFailure(
            ['Artifact ZIP failed the ZipArchive integrity check.'],
            true,
            ['pack warning'],
        );
        $restored = WrapupResult::fromArray($integrity->toArray());
        self::assertFalse($restored->success);
        self::assertTrue($restored->workDirDeleted);
        self::assertTrue($restored->hasIntegrityFailure());
        self::assertSame(['Artifact ZIP failed the ZipArchive integrity check.'], $restored->integrityErrors);
        self::assertNull($restored->deletionFailure);
        self::assertSame(['pack warning'], $restored->warnings);
        self::assertSame($integrity->toArray(), $restored->toArray());

        $deletion = WrapupResult::deletionFailure(
            'Wrapup refused to delete a work-directory entry that escapes the jail.',
            ['pack warning'],
        );
        $restoredDeletion = WrapupResult::fromArray($deletion->toArray());
        self::assertFalse($restoredDeletion->success);
        self::assertFalse($restoredDeletion->workDirDeleted);
        self::assertFalse($restoredDeletion->hasIntegrityFailure());
        self::assertSame([], $restoredDeletion->integrityErrors);
        self::assertSame('Wrapup refused to delete a work-directory entry that escapes the jail.', $restoredDeletion->deletionFailure);
        self::assertSame(['pack warning'], $restoredDeletion->warnings);
        self::assertSame($deletion->toArray(), $restoredDeletion->toArray());
    }

    public function testFromArrayRejectsDataThatIsNotAnArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WrapupResult::fromArray('nope');
    }

    #[DataProvider('malformedPersistedProvider')]
    public function testFromArrayRejectsMalformedPersistedFields(string $key, mixed $value): void
    {
        $base = WrapupResult::completed(true, ['pack warning'])->toArray();
        $base[$key] = $value;

        $this->expectException(InvalidArgumentException::class);
        WrapupResult::fromArray($base);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function malformedPersistedProvider(): iterable
    {
        yield 'success is not a bool' => ['success', 'yes'];
        yield 'work_dir_deleted is not a bool' => ['work_dir_deleted', 1];
        yield 'integrity_errors is not a list of strings' => ['integrity_errors', ['ok', 12]];
        yield 'deletion_failure is not a string or null' => ['deletion_failure', []];
        yield 'warnings is not a list of strings' => ['warnings', 'nope'];
    }
}
