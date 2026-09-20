<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Http;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * The request never completed at the HTTP layer (DNS, connect, timeout, TLS).
 * Wraps the PSR-18 exception as $previous; the message carries only the method
 * and URI so Authorization/credentials can never leak into logs or error text.
 */
final class TransportException extends HttpException
{
    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        ClientExceptionInterface $previous,
    ) {
        parent::__construct(sprintf('Transport error on %s %s', $method, $uri), 0, $previous);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }
}
