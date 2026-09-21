<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * Pure size-based route selection: at or under the 100 MiB upload limit the
 * archive goes through a multipart POST, above it through TUS. A later
 * transport adapter turns the chosen route into actual HTTP; the orchestration
 * only records and carries the decision.
 */
final class UploadRouter
{
    public function route(int $sizeBytes): UploadRoute
    {
        return $sizeBytes <= Contract::UPLOAD_LIMIT_BYTES ? UploadRoute::Post : UploadRoute::Tus;
    }
}
