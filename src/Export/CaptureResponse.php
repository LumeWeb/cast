<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Typed HTTP response with status, case-insensitive headers and a body stream.
 * Responses are always constructed through the static factories so test and
 * production adapters share one shape; the body is a CaptureBody (php://temp
 * or a real temporary file) — never a run-sized in-memory array.
 */
final class CaptureResponse
{
    /**
     * @param array<string, string|list<string>> $headers Lowercase keys.
     */
    private function __construct(
        private readonly int $status,
        private readonly array $headers,
        private readonly ?CaptureBody $body,
    ) {
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function withBody(int $status, array $headers, CaptureBody $body): self
    {
        return new self($status, $headers, $body);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function withString(int $status, array $headers, string $body): self
    {
        return new self($status, $headers, CaptureBody::fromString($body));
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function empty(int $status, array $headers = []): self
    {
        return new self($status, $headers, null);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * Case-insensitive header lookup; the first value when the header repeats.
     */
    public function header(string $name): ?string
    {
        $key = strtolower($name);
        if (!isset($this->headers[$key])) {
            return null;
        }

        $value = $this->headers[$key];

        return is_array($value) ? (string) reset($value) : (string) $value;
    }

    public function location(): ?string
    {
        return $this->header('location');
    }

    public function body(): ?CaptureBody
    {
        return $this->body;
    }
}
