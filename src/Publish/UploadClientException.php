<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * A transport-level upload failure (connection, auth, 4xx/5xx on POST or TUS).
 * The orchestration treats it as a hard, non-resumable publish failure: no CID
 * was produced, so nothing else may happen.
 */
final class UploadClientException extends \RuntimeException
{
}
