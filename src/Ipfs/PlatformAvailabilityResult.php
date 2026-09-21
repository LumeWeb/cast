<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * One per-platform availability answer for a candidate subdomain label, as
 * returned by the ipfs-sdk CheckPlatformDomainAvailability call: whether the
 * label can be claimed under a specific platform root and namespace.
 */
final class PlatformAvailabilityResult
{
    private function __construct(
        private readonly string $platformDomain,
        private readonly string $namespace,
        private readonly bool $available,
    ) {
    }

    /**
     * @throws ResponseDecodingException when a required field is missing or the wrong type.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ResponseDecodingException('Platform availability result is not a JSON object.');
        }

        return new self(
            self::stringField($data, 'platform_domain'),
            self::stringField($data, 'namespace'),
            self::boolField($data, 'available'),
        );
    }

    public function platformDomain(): string
    {
        return $this->platformDomain;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function available(): bool
    {
        return $this->available;
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new ResponseDecodingException(sprintf('Platform availability result is missing string field "%s".', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function boolField(array $data, string $key): bool
    {
        if (!isset($data[$key]) || !is_bool($data[$key])) {
            throw new ResponseDecodingException(sprintf('Platform availability result is missing boolean field "%s".', $key));
        }

        return $data[$key];
    }
}
