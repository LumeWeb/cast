<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * The typed result of POST /api/websites/{id}/validate as returned by the
 * ipfs-sdk Websites.ValidateDNS call (WebsiteValidateResponse): whether the
 * website's DNS records currently validate, the server's message/reason, and
 * the per-record checks the operator must converge. Every check is
 * server-computed — Cast only parses and renders it, never derives a record
 * value client-side.
 */
final class WebsiteValidation
{
    /**
     * @param list<CheckInfo> $checks
     */
    private function __construct(
        private readonly int $id,
        private readonly string $domain,
        private readonly bool $valid,
        private readonly string $message,
        private readonly string $reason,
        private readonly array $checks,
    ) {
    }

    /**
     * @throws ResponseDecodingException when the payload is not a JSON object or a field is the wrong type.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ResponseDecodingException('Website validation response is not a JSON object.');
        }

        $checks = [];
        if (isset($data['checks']) && is_array($data['checks'])) {
            foreach ($data['checks'] as $check) {
                $checks[] = CheckInfo::fromArray($check);
            }
        }

        return new self(
            self::intField($data, 'id'),
            self::stringField($data, 'domain'),
            self::boolField($data, 'valid'),
            self::stringField($data, 'message', ''),
            self::stringField($data, 'reason', ''),
            $checks,
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

    public function valid(): bool
    {
        return $this->valid;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * @return list<CheckInfo>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * @param array<mixed> $data
     */
    private static function intField(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new ResponseDecodingException(sprintf('Website validation response is missing integer field "%s".', $key));
        }

        return $data[$key];
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
            throw new ResponseDecodingException(sprintf('Website validation response field "%s" is not a string.', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function boolField(array $data, string $key): bool
    {
        if (!isset($data[$key]) || !is_bool($data[$key])) {
            throw new ResponseDecodingException(sprintf('Website validation response is missing boolean field "%s".', $key));
        }

        return $data[$key];
    }
}
