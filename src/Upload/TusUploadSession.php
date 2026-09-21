<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Upload;

use LumeWeb\Cast\Publish\UploadIdentifier;

/**
 * The typed outcome of a TUS upload session: the server upload URL the chunks
 * were PATCHed against, the current byte offset the server reported, the
 * logical artifact size, and the upload identifier (the TUS Upload-Key) that a
 * later result poll is carried out with. It follows the Publish
 * UploadIdentifier / UploadResult vocabulary so a bridge onto UploadClient can
 * poll /api/upload/result/{id} with identifier() while uploadUrl/offsetBytes
 * are preserved for resume and cancellation.
 */
final class TusUploadSession
{
    public function __construct(
        public readonly bool $completed,
        public readonly string $uploadUrl,
        public readonly int $offsetBytes,
        public readonly int $sizeBytes,
        private readonly UploadIdentifier $identifier,
    ) {
    }

    public function identifier(): UploadIdentifier
    {
        return $this->identifier;
    }
}
