<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The exact WordPress origin (scheme + host + effective port) that capture may
 * fetch. Built from a canonical Url; matches() is the SSRF check every caller
 * must pass before making a request.
 */
final class Origin
{
    private function __construct(
        private readonly string $scheme,
        private readonly string $host,
        private readonly ?int $port,
    ) {
    }

    public static function fromUrl(Url $url): self
    {
        return new self($url->scheme(), $url->host(), $url->port());
    }

    /**
     * Rebuild an origin from its three persisted parts. The explicit port
     * stays null when the scheme's default port applied, so the exact original
     * identity (including host case) survives a persisted round-trip and the
     * effective port remains derivable from the scheme.
     */
    public static function fromParts(string $scheme, string $host, ?int $port): self
    {
        return new self($scheme, $host, $port);
    }

    public function scheme(): string
    {
        return $this->scheme;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): ?int
    {
        return $this->port;
    }

    /**
     * Exact allowlist check: scheme and host must match and the effective
     * ports must agree (omitted default ports equal their explicit form).
     */
    public function matches(Url $url): bool
    {
        return $this->scheme === $url->scheme()
            && $this->host === $url->host()
            && $this->effectivePort() === $url->effectivePort();
    }

    private function effectivePort(): int
    {
        return $this->port ?? (Url::DEFAULT_PORTS[$this->scheme] ?? 0);
    }

    public function __toString(): string
    {
        return $this->scheme . '://' . $this->host . ($this->port === null ? '' : ':' . $this->port);
    }
}
