<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use LumeWeb\Cast\Publish\UploadResult;
use LumeWeb\Cast\Publish\UploadStatus;
use PHPUnit\Framework\TestCase;

/**
 * A single poll response DTO: the upload status plus the terminal fields the
 * publish orchestration needs (CID, size, DAG size, location, message).
 */
final class UploadResultTest extends TestCase
{
    public function testCompletedCarriesTerminalCidFields(): void
    {
        $result = new UploadResult(
            UploadStatus::Completed,
            cid: 'QmHash',
            size: 2048,
            dagSize: 3000,
            location: 'https://gateway.example/QmHash',
        );

        self::assertTrue($result->isTerminal());
        self::assertTrue($result->isSuccess());
        self::assertSame('QmHash', $result->cid);
        self::assertSame(2048, $result->size);
        self::assertSame(3000, $result->dagSize);
        self::assertSame('https://gateway.example/QmHash', $result->location);
    }

    public function testFailedIsTerminalButNotSuccess(): void
    {
        $result = new UploadResult(UploadStatus::Failed, message: 'Server rejected the archive');

        self::assertTrue($result->isTerminal());
        self::assertFalse($result->isSuccess());
        self::assertSame('Server rejected the archive', $result->message);
    }

    public function testPendingIsNeitherTerminalNorSuccess(): void
    {
        $result = new UploadResult(UploadStatus::Pending);

        self::assertFalse($result->isTerminal());
        self::assertFalse($result->isSuccess());
        self::assertNull($result->cid);
    }
}
