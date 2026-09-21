<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * The delegation guidance for a bound domain as returned by the ipfs-sdk
 * DomainResponse delegation block: how the zone is run (null when the portal
 * does not manage it, otherwise "inline"), the nameservers the zone points at,
 * the DNSSEC state, and the DNS records the user must publish at the parent
 * (DS/NS, optionally with glue) and authoritative (TLSA/TXT) side to complete
 * delegation.
 */
final class DelegationInfo
{
    private function __construct(
        private readonly ?string $mode,
        /** @var list<string> */
        private readonly array $nameservers,
        private readonly ?string $dnssec,
        private readonly ?string $dnssecError,
        /** @var list<DnsRecord> */
        private readonly array $parentRecords,
        /** @var list<DnsRecord> */
        private readonly array $authoritativeRecords,
    ) {
    }

    /**
     * @throws ResponseDecodingException when the payload is not a JSON object or a field is the wrong type.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ResponseDecodingException('Domain delegation response is not a JSON object.');
        }

        return new self(
            self::optionalStringField($data, 'mode'),
            self::stringListField($data, 'nameservers'),
            self::optionalStringField($data, 'dnssec'),
            self::optionalStringField($data, 'dnssec_error'),
            self::recordListField($data, 'parent_records'),
            self::recordListField($data, 'authoritative_records'),
        );
    }

    public function mode(): ?string
    {
        return $this->mode;
    }

    /**
     * @return list<string>
     */
    public function nameservers(): array
    {
        return $this->nameservers;
    }

    public function dnssec(): ?string
    {
        return $this->dnssec;
    }

    public function dnssecError(): ?string
    {
        return $this->dnssecError;
    }

    /**
     * @return list<DnsRecord>
     */
    public function parentRecords(): array
    {
        return $this->parentRecords;
    }

    /**
     * @return list<DnsRecord>
     */
    public function authoritativeRecords(): array
    {
        return $this->authoritativeRecords;
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
            throw new ResponseDecodingException(sprintf('Domain delegation response field "%s" is not a string.', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private static function stringListField(array $data, string $key): array
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return [];
        }

        if (!is_array($data[$key])) {
            throw new ResponseDecodingException(sprintf('Domain delegation response field "%s" is not an array.', $key));
        }

        $values = [];
        foreach ($data[$key] as $value) {
            if (!is_string($value)) {
                throw new ResponseDecodingException(sprintf('Domain delegation response field "%s" contains a non-string value.', $key));
            }
            $values[] = $value;
        }

        return $values;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<DnsRecord>
     */
    private static function recordListField(array $data, string $key): array
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return [];
        }

        if (!is_array($data[$key])) {
            throw new ResponseDecodingException(sprintf('Domain delegation response field "%s" is not an array.', $key));
        }

        $records = [];
        foreach ($data[$key] as $record) {
            $records[] = DnsRecord::fromArray($record);
        }

        return $records;
    }
}
