<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Http;

/**
 * Base type for the SDK's typed HTTP errors, so callers can catch the whole
 * family with a single catch block.
 */
class HttpException extends \RuntimeException
{
}
