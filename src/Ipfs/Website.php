<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * A portal website as returned by the ipfs-sdk Websites.Create/Update
 * responses (WebsiteResponse). Immutable and minimal: id, lifecycle status,
 * the bound domain (an empty string on first publish, when no domain is bound
 * yet), the CID the site currently points at, the IPNS key id it is wired to,
 * and the target it was created/updated with.
 */
final class Website
{
    private function __construct(
        private readonly int $id,
        private readonly string $status,
        private readonly string $domain,
        private readonly ?string $activeCid,
        private readonly ?int $ipnsKeyId,
        private readonly string $targetHash,
        private readonly string $targetType,
    ) {
    }

    /**
     * @throws ResponseDecodingException when a required field is missing or the wrong type.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ResponseDecodingException('Website response is not a JSON object.');
        }

        return new self(
            self::intField($data, 'id'),
            self::stringField($data, 'status'),
            self::stringField($data, 'domain'),
            self::optionalStringField($data, 'active_cid'),
            self::optionalIntField($data, 'ipns_key_id'),
            self::stringField($data, 'target_hash'),
            self::stringField($data, 'target_type'),
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function activeCid(): ?string
    {
        return $this->activeCid;
    }

    public function ipnsKeyId(): ?int
    {
        return $this->ipnsKeyId;
    }

    public function targetHash(): string
    {
        return $this->targetHash;
    }

    public function targetType(): string
    {
        return $this->targetType;
    }

    /**
     * @param array<mixed> $data
     */
    private static function intField(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new ResponseDecodingException(sprintf('Website response is missing integer field "%s".', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new ResponseDecodingException(sprintf('Website response is missing string field "%s".', $key));
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
            throw new ResponseDecodingException(sprintf('Website response field "%s" is not a string.', $key));
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
            throw new ResponseDecodingException(sprintf('Website response field "%s" is not an integer.', $key));
        }

        return $data[$key];
    }
}
