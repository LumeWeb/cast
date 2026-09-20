<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Immutable canonical URL identity.
 *
 * Built by UrlCanonicalizer and stored on work items; never mutated after
 * construction. Port is null whenever the scheme's default port applies, so
 * only non-default ports participate in identity.
 */
final class Url
{
    public const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public function __construct(
        private readonly string $scheme,
        private readonly string $host,
        private readonly ?int $port,
        private readonly string $path,
        private readonly string $query,
    ) {
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

    public function path(): string
    {
        return $this->path;
    }

    public function query(): string
    {
        return $this->query;
    }

    public function hasQuery(): bool
    {
        return $this->query !== '';
    }

    /**
     * Effective port for comparisons: the explicit port, or the scheme's
     * default when the URL omitted it (80 for http, 443 for https).
     */
    public function effectivePort(): int
    {
        return $this->port ?? (self::DEFAULT_PORTS[$this->scheme] ?? 0);
    }

    public function authority(): string
    {
        return $this->port === null ? $this->host : $this->host . ':' . $this->port;
    }

    /**
     * scheme://authority + path with no query; the identity base for assets,
     * which never keep their query string.
     */
    public function base(): string
    {
        return $this->scheme . '://' . $this->authority() . $this->path;
    }

    public function __toString(): string
    {
        return $this->base() . ($this->hasQuery() ? '?' . $this->query : '');
    }
}
