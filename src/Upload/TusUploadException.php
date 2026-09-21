<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Upload;

/**
 * A TUS upload failed at the transport/server layer: the create-with-upload
 * request was rejected, a chunk PATCH or HEAD was rejected, the upload could
 * not be resumed, or cancellation could not be carried out. The message is
 * generic — it never includes the bearer token, request headers or raw server
 * bodies; the original error is chained only as $previous for debugging.
 */
final class TusUploadException extends UploadException
{
}
