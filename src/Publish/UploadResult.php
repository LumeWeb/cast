<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * One upload-result poll response: the status plus the terminal fields the
 * publish orchestration consumes (CID, size, DAG size, location, message).
 */
final class UploadResult
{
    public function __construct(
        public readonly UploadStatus $status,
        public readonly ?string $cid = null,
        public readonly ?int $size = null,
        public readonly ?int $dagSize = null,
        public readonly ?string $location = null,
        public readonly ?string $message = null,
    ) {
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function isSuccess(): bool
    {
        return $this->status->isSuccess();
    }
}
