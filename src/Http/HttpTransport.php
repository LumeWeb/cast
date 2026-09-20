<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * PSR-18 request/response boundary shared by every SDK client. Requests are
 * built with injected PSR-17 factories and sent on the injected PSR-18 client;
 * the raw PSR-7 response is translated into a typed HttpResponse. Transport
 * failures (connect, DNS, timeout, TLS) are wrapped as TransportException with
 * a message that never includes request headers, so bearer credentials cannot
 * leak into logs.
 */
final class HttpTransport
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * @param array<string, string>                 $headers Header name => value.
     * @param null|string|StreamInterface          $body    Optional request body: a literal string, or a
     *                                                      stream (used to stream multipart file uploads
     *                                                      without buffering the whole payload).
     */
    public function send(string $method, string $uri, array $headers = [], null|string|StreamInterface $body = null): HttpResponse
    {
        $request = $this->requestFactory->createRequest($method, $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $stream = $body instanceof StreamInterface ? $body : $this->streamFactory->createStream($body);
            $request = $request->withBody($stream);
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException($method, $uri, $e);
        }

        return new HttpResponse($response);
    }
}
