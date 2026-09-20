<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Typed, JSON-aware view of a PSR-7 response for SDK clients. Headers and raw
 * bodies are read on demand; json() surfaces malformed payloads as
 * ResponseDecodingException and requireSuccess() turns non-2xx into
 * UnexpectedStatusCodeException without echoing sensitive data.
 */
final class HttpResponse
{
    public function __construct(
        private readonly ResponseInterface $response,
    ) {
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function reason(): string
    {
        return $this->response->getReasonPhrase();
    }

    public function isSuccess(): bool
    {
        return $this->status() >= 200 && $this->status() < 300;
    }

    /**
     * @return array<string, string[]> Header name => values as sent by the server.
     */
    public function headers(): array
    {
        return $this->response->getHeaders();
    }

    public function header(string $name): ?string
    {
        return $this->response->hasHeader($name) ? $this->response->getHeaderLine($name) : null;
    }

    public function body(): string
    {
        return (string) $this->response->getBody();
    }

    /**
     * @throws UnexpectedStatusCodeException when the status is not 2xx.
     */
    public function requireSuccess(): self
    {
        if (!$this->isSuccess()) {
            throw new UnexpectedStatusCodeException($this);
        }

        return $this;
    }

    /**
     * @return array<mixed> Decoded JSON body.
     *
     * @throws ResponseDecodingException when the body is empty, not valid JSON, or not an object/array.
     */
    public function json(): array
    {
        $body = $this->body();
        if ($body === '') {
            throw new ResponseDecodingException('Response body is empty; expected JSON.');
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ResponseDecodingException('Response body is not valid JSON.', 0, $e);
        }

        if (!is_array($decoded)) {
            throw new ResponseDecodingException('Response JSON is not an object or array.');
        }

        return $decoded;
    }
}
