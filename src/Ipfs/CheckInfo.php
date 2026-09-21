<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * A domain validation check reported by the ipfs-sdk DomainResponse checks
 * list (e.g. the dnslink record check). Each check carries the rule name, an
 * ok flag, and the human-readable guidance the user needs to converge the
 * record: the value that is expected and the value found in DNS (both empty
 * when the record has not been published yet).
 */
final class CheckInfo
{
    private function __construct(
        private readonly string $name,
        private readonly bool $ok,
        private readonly string $message,
        private readonly string $expected,
        private readonly string $found,
    ) {
    }

    /**
     * @throws ResponseDecodingException when a required field is missing or the wrong type.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ResponseDecodingException('Domain check response is not a JSON object.');
        }

        return new self(
            self::stringField($data, 'name'),
            self::boolField($data, 'ok'),
            self::stringField($data, 'message', ''),
            self::stringField($data, 'expected', ''),
            self::stringField($data, 'found', ''),
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function ok(): bool
    {
        return $this->ok;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function expected(): string
    {
        return $this->expected;
    }

    public function found(): string
    {
        return $this->found;
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringField(array $data, string $key, string $default = ''): string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return $default;
        }

        if (!is_string($data[$key])) {
            throw new ResponseDecodingException(sprintf('Domain check response field "%s" is not a string.', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function boolField(array $data, string $key): bool
    {
        if (!isset($data[$key]) || !is_bool($data[$key])) {
            throw new ResponseDecodingException(sprintf('Domain check response is missing boolean field "%s".', $key));
        }

        return $data[$key];
    }
}
