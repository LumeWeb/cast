<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Http;

/**
 * The response body could not be interpreted as the JSON object/array the SDK
 * client expected (empty body, syntax error, or non-object root).
 */
final class ResponseDecodingException extends HttpException
{
}
