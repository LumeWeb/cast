<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use LumeWeb\Cast\Publish\UploadStatus;
use PHPUnit\Framework\TestCase;

/**
 * The upload result vocabulary mirrored from the ipfs-sdk contract: terminal
 * outcomes are completed/duplicate/failed, and completing or producing a
 * duplicate both count as a successful upload (a CID exists).
 */
final class UploadStatusTest extends TestCase
{
    public function testTerminalStatuses(): void
    {
        self::assertTrue(UploadStatus::Completed->isTerminal());
        self::assertTrue(UploadStatus::Duplicate->isTerminal());
        self::assertTrue(UploadStatus::Failed->isTerminal());
        self::assertFalse(UploadStatus::Pending->isTerminal());
        self::assertFalse(UploadStatus::Processing->isTerminal());
    }

    public function testSuccessStatuses(): void
    {
        self::assertTrue(UploadStatus::Completed->isSuccess());
        self::assertTrue(UploadStatus::Duplicate->isSuccess());
        self::assertFalse(UploadStatus::Failed->isSuccess());
        self::assertFalse(UploadStatus::Pending->isSuccess());
        self::assertFalse(UploadStatus::Processing->isSuccess());
    }
}
