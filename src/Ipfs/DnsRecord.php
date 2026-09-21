<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * A single DNS record shown to a user for a domain binding, as carried by the
 * ipfs-sdk DomainResponse delegation block (parent_records and
 * authoritative_records). Records are heterogeneous: an NS/DS parent record
 * may carry only type+value (+optional glue address), while an authoritative
 * TLSA/TXT record carries type+value (+optional ns owner). Every field that a
 * given record shape does not supply is surfaced as null.
 */
final class DnsRecord
{
    private function __construct(
        private readonly string $type,
        private readonly string $value,
        private readonly ?string $address,
        private readonly ?string $ns,
    ) {
    }

    /**
     * @throws ResponseDecodingException when a required field is missing or the wrong type.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ResponseDecodingException('DNS record response is not a JSON object.');
        }

        return new self(
            self::stringField($data, 'type'),
            self::stringField($data, 'value'),
            self::optionalStringField($data, 'address'),
            self::optionalStringField($data, 'ns'),
        );
    }

    public function type(): string
    {
        return $this->type;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function address(): ?string
    {
        return $this->address;
    }

    public function ns(): ?string
    {
        return $this->ns;
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new ResponseDecodingException(sprintf('DNS record response is missing string field "%s".', $key));
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
            throw new ResponseDecodingException(sprintf('DNS record response field "%s" is not a string.', $key));
        }

        return $data[$key];
    }
}
