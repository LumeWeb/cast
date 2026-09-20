<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * A type-safe snapshot of what wp_remote_get() returned, produced by the
 * WordPress HTTP adapter so the pure transport never touches WP_Error or the
 * raw response array. A streamed body is handed over as a temporary filename;
 * the body string is populated for non-streamed responses.
 */
final class WpRemoteResponse
{
    /**
     * @param array<string, string|list<string>> $headers Lowercase keys.
     */
    private function __construct(
        private readonly bool $isError,
        private readonly string $errorCode,
        private readonly string $errorMessage,
        private readonly int $status,
        private readonly array $headers,
        private readonly string $body,
        private readonly ?string $filename,
    ) {
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function success(int $status, array $headers, string $body = '', ?string $filename = null): self
    {
        return new self(false, '', '', $status, $headers, $body, $filename);
    }

    public static function error(string|int $code, string $message): self
    {
        return new self(true, (string) $code, $message, 0, [], '', null);
    }

    public function isError(): bool
    {
        return $this->isError;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function errorMessage(): string
    {
        return $this->errorMessage;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function filename(): ?string
    {
        return $this->filename;
    }
}
