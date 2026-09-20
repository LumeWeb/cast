<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * Upload result vocabulary mirrored from the ipfs-sdk contract. Terminal
 * outcomes are completed, duplicate and failed; completed and duplicate both
 * mean a CID now exists, while failed aborts the publish. pending/processing
 * are polled with backoff.
 */
enum UploadStatus: string
{
    case Completed = 'completed';
    case Duplicate = 'duplicate';
    case Failed = 'failed';
    case Pending = 'pending';
    case Processing = 'processing';

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Duplicate || $this === self::Failed;
    }

    public function isSuccess(): bool
    {
        return $this === self::Completed || $this === self::Duplicate;
    }
}
