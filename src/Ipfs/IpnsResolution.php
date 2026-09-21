<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * An IPNS resolution as returned by the ipfs-sdk IPNS.Resolve response
 * (IPNSResolveResponse, GET /api/ipns/resolve/{name}). Immutable and minimal:
 * the name resolved, the raw value it points at (an /ipfs/{cid} path), the
 * path form, the sequence, expiry metadata, and the derived immutable CID the
 * name currently resolves to.
 */
final class IpnsResolution
{
    private function __construct(
        private readonly string $name,
        private readonly string $value,
        private readonly string $path,
        private readonly int $sequence,
        private readonly bool $expired,
        private readonly string $expires,
    ) {
    }

    /**
     * @throws ResponseDecodingException when a required field is missing or the wrong type.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ResponseDecodingException('IPNS resolution response is not a JSON object.');
        }

        return new self(
            self::stringField($data, 'name'),
            self::stringField($data, 'value'),
            self::stringField($data, 'path'),
            self::intField($data, 'sequence'),
            self::boolField($data, 'expired'),
            self::stringField($data, 'expires'),
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function expired(): bool
    {
        return $this->expired;
    }

    public function expires(): string
    {
        return $this->expires;
    }

    /**
     * The immutable root CID the name currently points at. The portal returns
     * the resolved value as an /ipfs/{cid} (or /ipfs/{cid}/{path}) string —
     * the sdk parses it as an immutable path and reads the root cid — so the
     * first path segment is the CID; a bare CID is returned unchanged.
     */
    public function cid(): string
    {
        if (!str_starts_with($this->value, '/ipfs/')) {
            return $this->value;
        }

        $rest = substr($this->value, strlen('/ipfs/'));
        $segments = explode('/', $rest);
        $cid = $segments[0] ?? '';

        return $cid !== '' ? $cid : $this->value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new ResponseDecodingException(sprintf('IPNS resolution response is missing string field "%s".', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function intField(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new ResponseDecodingException(sprintf('IPNS resolution response is missing integer field "%s".', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function boolField(array $data, string $key): bool
    {
        if (!isset($data[$key]) || !is_bool($data[$key])) {
            throw new ResponseDecodingException(sprintf('IPNS resolution response is missing boolean field "%s".', $key));
        }

        return $data[$key];
    }
}
