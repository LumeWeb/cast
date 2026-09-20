<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Portal;

use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * The account identity Cast needs, mapped from the portal GetAccount
 * (/api/account) response (AccountInfoResponse). Immutable and deliberately
 * minimal: id, email, names and verification state — nothing more.
 */
final class Account
{
    private function __construct(
        private readonly int $id,
        private readonly string $email,
        private readonly string $firstName,
        private readonly string $lastName,
        private readonly bool $verified,
    ) {
    }

    /**
     * @throws ResponseDecodingException when a required identity field is missing or the wrong type.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ResponseDecodingException('Account response is not a JSON object.');
        }

        return new self(
            self::intField($data, 'id'),
            self::stringField($data, 'email'),
            self::stringField($data, 'first_name'),
            self::stringField($data, 'last_name'),
            self::boolField($data, 'verified'),
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function verified(): bool
    {
        return $this->verified;
    }

    /**
     * @param array<mixed> $data
     */
    private static function intField(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new ResponseDecodingException(sprintf('Account response is missing integer field "%s".', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new ResponseDecodingException(sprintf('Account response is missing string field "%s".', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function boolField(array $data, string $key): bool
    {
        if (!isset($data[$key]) || !is_bool($data[$key])) {
            throw new ResponseDecodingException(sprintf('Account response is missing boolean field "%s".', $key));
        }

        return $data[$key];
    }
}
