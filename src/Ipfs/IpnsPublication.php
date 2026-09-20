<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * An IPNS publication as returned by the ipfs-sdk IPNS.Publish response
 * (IPNSPublishResponse). Immutable and minimal: the resolved value (the
 * /ipfs/{cid} path), the name published under, the sequence number, and the
 * published/validity timestamps.
 */
final class IpnsPublication
{
    private function __construct(
        private readonly string $name,
        private readonly string $value,
        private readonly int $sequence,
        private readonly string $published,
        private readonly string $validity,
    ) {
    }

    /**
     * @throws ResponseDecodingException when a required field is missing or the wrong type.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ResponseDecodingException('IPNS publish response is not a JSON object.');
        }

        return new self(
            self::stringField($data, 'name'),
            self::stringField($data, 'value'),
            self::intField($data, 'sequence'),
            self::stringField($data, 'published'),
            self::stringField($data, 'validity'),
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

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function published(): string
    {
        return $this->published;
    }

    public function validity(): string
    {
        return $this->validity;
    }

    /**
     * @param array<mixed> $data
     */
    private static function intField(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new ResponseDecodingException(sprintf('IPNS publish response is missing integer field "%s".', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new ResponseDecodingException(sprintf('IPNS publish response is missing string field "%s".', $key));
        }

        return $data[$key];
    }
}
