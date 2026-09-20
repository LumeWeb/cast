<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * An IPNS key/publish failure. The website and the upload CID survive it, so
 * the orchestration returns a resumable state rather than destroying progress.
 */
final class IpnsClientException extends \RuntimeException
{
}
