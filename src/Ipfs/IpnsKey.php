<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * An IPNS key as returned by the ipfs-sdk IPNS.CreateKey response
 * (IPNSKeyResponse). Immutable and minimal: the numeric portal key id, the
 * human name, the mutable IPNS name, the peer id, the creation timestamp, and
 * the optional last published value/at.
 */
final class IpnsKey
{
    private function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly string $ipnsName,
        private readonly string $peerId,
        private readonly string $created,
        private readonly ?string $value,
        private readonly ?string $lastPublishedAt,
    ) {
    }

    /**
     * @throws ResponseDecodingException when a required field is missing or the wrong type.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ResponseDecodingException('IPNS key response is not a JSON object.');
        }

        return new self(
            self::intField($data, 'id'),
            self::stringField($data, 'name'),
            self::stringField($data, 'ipns_name'),
            self::stringField($data, 'peer_id'),
            self::stringField($data, 'created'),
            self::optionalStringField($data, 'value'),
            self::optionalStringField($data, 'last_published_at'),
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function ipnsName(): string
    {
        return $this->ipnsName;
    }

    public function peerId(): string
    {
        return $this->peerId;
    }

    public function created(): string
    {
        return $this->created;
    }

    public function value(): ?string
    {
        return $this->value;
    }

    public function lastPublishedAt(): ?string
    {
        return $this->lastPublishedAt;
    }

    /**
     * @param array<mixed> $data
     */
    private static function intField(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new ResponseDecodingException(sprintf('IPNS key response is missing integer field "%s".', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new ResponseDecodingException(sprintf('IPNS key response is missing string field "%s".', $key));
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
            throw new ResponseDecodingException(sprintf('IPNS key response field "%s" is not a string.', $key));
        }

        return $data[$key];
    }
}
