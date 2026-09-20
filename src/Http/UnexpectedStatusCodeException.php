<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Http;

/**
 * A non-2xx status came back for a request the caller treats as success-only.
 * The message carries status + reason only (no headers, no body, no
 * credentials); callers read the carried response when they need the error
 * payload for per-endpoint mapping.
 */
final class UnexpectedStatusCodeException extends HttpException
{
    public function __construct(
        private readonly HttpResponse $response,
    ) {
        parent::__construct(sprintf('Unexpected HTTP status %d %s', $response->status(), $response->reason()));
    }

    public function response(): HttpResponse
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->status();
    }
}
