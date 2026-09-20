<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Upload;

/**
 * Base type for errors raised by the upload transport adapters that are not
 * HTTP-layer failures: artifact file problems and redirect-policy violations.
 * The typed HttpException family from the shared transport (transport,
 * unexpected status, decoding) is preserved unchanged for network, status and
 * response-decoding failures.
 */
class UploadException extends \RuntimeException
{
}
