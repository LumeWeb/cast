<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * SSL/TLS state for a bound domain, embedded in the ipfs-sdk DomainResponse
 * ssl block and surfaced standalone by the ssl-status endpoint. Carries the
 * lifecycle status (e.g. "issuing" or "ready"), the issue/renew timestamps
 * when known, and an optional human-readable error while provisioning.
 */
final class SslStatusInfo
{
    private function __construct(
        private readonly string $status,
        private readonly ?string $issuedAt,
        private readonly ?string $lastUpdatedAt,
        private readonly ?string $error,
    ) {
    }

    /**
     * @throws ResponseDecodingException when the payload is not a JSON object or a required field is wrong.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ResponseDecodingException('SSL status response is not a JSON object.');
        }

        return new self(
            self::stringField($data, 'status'),
            self::optionalStringField($data, 'issued_at'),
            self::optionalStringField($data, 'last_updated_at'),
            self::optionalStringField($data, 'error'),
        );
    }

    public function status(): string
    {
        return $this->status;
    }

    public function issuedAt(): ?string
    {
        return $this->issuedAt;
    }

    public function lastUpdatedAt(): ?string
    {
        return $this->lastUpdatedAt;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new ResponseDecodingException(sprintf('SSL status response is missing string field "%s".', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function optionalStringField(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        if (!is_string($data[$key])) {
            throw new ResponseDecodingException(sprintf('SSL status response field "%s" is not a string.', $key));
        }

        return $data[$key];
    }
}
