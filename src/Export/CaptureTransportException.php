<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * A transport-level failure (DNS, connection refused, timeout, TLS) that is
 * retryable: capture retries it up to the bounded attempt cap before marking
 * the item failed.
 */
final class CaptureTransportException extends \RuntimeException
{
}
