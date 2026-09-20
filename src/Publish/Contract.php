<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * Constants mirrored from the ipfs-sdk contract, kept in one place so the
 * route selection and the future POST/TUS adapters agree on the numbers.
 */
final class Contract
{
    /**
     * Uploads at or under this size use multipart POST; above it they must use
     * TUS. 100 MiB.
     */
    public const UPLOAD_LIMIT_BYTES = 100 * 1024 * 1024;

    /**
     * Redirect hops the multipart POST path follows before giving up.
     */
    public const MAX_REDIRECT_HOPS = 5;

    private function __construct()
    {
    }
}
