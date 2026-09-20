<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * The transport an upload will ride: a single multipart POST for small
 * archives, resumable TUS for large ones.
 */
enum UploadRoute: string
{
    case Post = 'post';
    case Tus = 'tus';
}
