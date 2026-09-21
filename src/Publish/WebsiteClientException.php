<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * A website create/update failure. The CID from the completed upload is not
 * lost by it, so the orchestration returns a resumable state instead of
 * discarding the upload.
 */
final class WebsiteClientException extends \RuntimeException
{
}
