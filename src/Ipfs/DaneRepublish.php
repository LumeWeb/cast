<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * The result of forcing re-publication of a bound domain's DANE records (the
 * _443._tcp.<domain> TLSA RRset) into the managed authoritative zone, as
 * returned by the ipfs-sdk RepublishDANE (DomainDANERepublishResponse) call.
 * Identifies the binding and reports whether the TLSA was published into the
 * managed zone, the TLSA rdata written, and the resulting binding status.
 */
final class DaneRepublish
{
    private function __construct(
        private readonly int $id,
        private readonly string $domain,
        private readonly string $namespace,
        private readonly bool $publishedToManagedZone,
        private readonly ?string $tlsaRdata,
        private readonly ?string $status,
    ) {
    }

    /**
     * @throws ResponseDecodingException when a required field is missing or the wrong type.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ResponseDecodingException('DANE republish response is not a JSON object.');
        }

        return new self(
            self::intField($data, 'id'),
            self::stringField($data, 'domain'),
            self::stringField($data, 'namespace'),
            self::boolField($data, 'published_to_managed_zone'),
            self::optionalStringField($data, 'tlsa_rdata'),
            self::optionalStringField($data, 'status'),
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

    public function publishedToManagedZone(): bool
    {
        return $this->publishedToManagedZone;
    }

    public function tlsaRdata(): ?string
    {
        return $this->tlsaRdata;
    }

    public function status(): ?string
    {
        return $this->status;
    }

    /**
     * @param array<mixed> $data
     */
    private static function intField(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new ResponseDecodingException(sprintf('DANE republish response is missing integer field "%s".', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new ResponseDecodingException(sprintf('DANE republish response is missing string field "%s".', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function boolField(array $data, string $key): bool
    {
        if (!isset($data[$key]) || !is_bool($data[$key])) {
            throw new ResponseDecodingException(sprintf('DANE republish response is missing boolean field "%s".', $key));
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
            throw new ResponseDecodingException(sprintf('DANE republish response field "%s" is not a string.', $key));
        }

        return $data[$key];
    }
}
