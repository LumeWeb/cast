<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * The transport boundary for uploads. A fake implements it in unit tests; a
 * future adapter maps it onto the real ipfs-sdk contract (multipart POST under
 * the 100 MiB limit, TUS above it, result polling). No credentials or
 * WordPress functions exist here — the adapter owns those.
 */
interface UploadClient
{
    public function upload(UploadSpec $spec): UploadIdentifier;

    public function poll(UploadIdentifier $identifier): UploadResult;
}
