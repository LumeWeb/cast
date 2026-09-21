<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * A platform (free-subdomain) root that websites can be bound under, as
 * returned by the ipfs-sdk ListPlatformDomains call. Each entry names the root
 * domain and namespace, the portal-managed DNS zone id it maps to, and whether
 * the root is currently accepting new labels.
 */
final class PlatformDomain
{
    private function __construct(
        private readonly int $id,
        private readonly string $domain,
        private readonly string $namespace,
        private readonly ?int $zoneId,
        private readonly bool $enabled,
    ) {
    }

    /**
     * @throws ResponseDecodingException when a required field is missing or the wrong type.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ResponseDecodingException('Platform domain response is not a JSON object.');
        }

        return new self(
            self::intField($data, 'id'),
            self::stringField($data, 'domain'),
            self::stringField($data, 'namespace'),
            self::optionalIntField($data, 'zone_id'),
            self::boolField($data, 'enabled'),
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function zoneId(): ?int
    {
        return $this->zoneId;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param array<mixed> $data
     */
    private static function intField(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new ResponseDecodingException(sprintf('Platform domain response is missing integer field "%s".', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new ResponseDecodingException(sprintf('Platform domain response is missing string field "%s".', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function optionalIntField(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        if (!is_int($data[$key])) {
            throw new ResponseDecodingException(sprintf('Platform domain response field "%s" is not an integer.', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function boolField(array $data, string $key): bool
    {
        if (!isset($data[$key]) || !is_bool($data[$key])) {
            throw new ResponseDecodingException(sprintf('Platform domain response is missing boolean field "%s".', $key));
        }

        return $data[$key];
    }
}
