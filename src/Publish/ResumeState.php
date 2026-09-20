<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * What a resumable publish preserved: the CID that was already produced, the
 * upload identifier it came from (TUS resume / re-poll), and the stage where
 * the run stalled so a retry picks up exactly there.
 */
final class ResumeState
{
    public function __construct(
        public readonly string $cid,
        public readonly UploadIdentifier $uploadIdentifier,
        public readonly PublishStage $stage,
    ) {
    }
}
