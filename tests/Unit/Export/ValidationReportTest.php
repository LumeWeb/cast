<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\PackStatus;
use LumeWeb\Cast\Export\ValidationFinding;
use LumeWeb\Cast\Export\ValidationReport;
use LumeWeb\Cast\Export\ValidationSeverity;
use PHPUnit\Framework\TestCase;

final class ValidationReportTest extends TestCase
{
    public function testEmptyFindingsSummarizeToCompleted(): void
    {
        $report = ValidationReport::summarize([], ['files' => 3]);

        self::assertSame(PackStatus::Completed, $report->status);
        self::assertFalse($report->hasViolation());
        self::assertSame([], $report->warnings());
        self::assertSame([], $report->errors());
        self::assertSame(['files' => 3], $report->counts);
    }

    public function testAnyWarningSummarizesToCompletedWithWarnings(): void
    {
        $findings = [
            new ValidationFinding('leftover_origin', ValidationSeverity::Warning, 'leftover origin'),
        ];

        $report = ValidationReport::summarize($findings, []);

        self::assertSame(PackStatus::CompletedWithWarnings, $report->status);
        self::assertTrue($report->hasViolation());
        self::assertCount(1, $report->warnings());
        self::assertCount(0, $report->errors());
    }

    public function testAnyHardFindingSummarizesToFailed(): void
    {
        $findings = [
            new ValidationFinding('pending_items', ValidationSeverity::Hard, 'still crawling'),
            new ValidationFinding('leftover_origin', ValidationSeverity::Warning, 'leftover origin'),
        ];

        $report = ValidationReport::summarize($findings, []);

        self::assertSame(PackStatus::Failed, $report->status);
        self::assertTrue($report->hasViolation());
        self::assertCount(1, $report->warnings());
        self::assertCount(1, $report->errors());

        $error = $report->errors()[0];
        self::assertSame('pending_items', $error->category);
        self::assertSame(ValidationSeverity::Hard, $error->severity);
    }

    public function testFindingCarriesPathAndReferenceForManifest(): void
    {
        $finding = new ValidationFinding(
            'broken_local_reference',
            ValidationSeverity::Warning,
            'missing target',
            'about/index.html',
            '/wp-content/uploads/photo.jpg',
        );

        self::assertSame('about/index.html', $finding->path);
        self::assertSame('/wp-content/uploads/photo.jpg', $finding->reference);
    }

    public function testPackStatusesExposeDesignStrings(): void
    {
        self::assertSame('completed', PackStatus::Completed->value);
        self::assertSame('completed_with_warnings', PackStatus::CompletedWithWarnings->value);
        self::assertSame('failed', PackStatus::Failed->value);
    }

    public function testHighSeverityWinsInSummarize(): void
    {
        $report = ValidationReport::summarize([
            new ValidationFinding('x', ValidationSeverity::Warning, 'w'),
        ], []);

        self::assertSame(PackStatus::CompletedWithWarnings, $report->status);
    }
}
