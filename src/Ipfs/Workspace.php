<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * The workspace identity Cast needs, mapped from the ipfs-sdk Workspaces.List
 * response (WorkspaceResponse). Immutable and minimal: id, label (name), the
 * bound domain (when present) and lifecycle status.
 */
final class Workspace
{
    private function __construct(
        private readonly int $id,
        private readonly string $label,
        private readonly ?string $domain,
        private readonly string $status,
    ) {
    }

    /**
     * @throws ResponseDecodingException when a required field is missing or the wrong type.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ResponseDecodingException('Workspace response item is not a JSON object.');
        }

        return new self(
            self::intField($data, 'id'),
            self::stringField($data, 'label'),
            self::optionalStringField($data, 'domain'),
            self::stringField($data, 'status'),
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    /**
     * The workspace name as shown in the portal.
     */
    public function label(): string
    {
        return $this->label;
    }

    public function domain(): ?string
    {
        return $this->domain;
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @param array<mixed> $data
     */
    private static function intField(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new ResponseDecodingException(sprintf('Workspace response is missing integer field "%s".', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new ResponseDecodingException(sprintf('Workspace response is missing string field "%s".', $key));
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
            throw new ResponseDecodingException(sprintf('Workspace response field "%s" is not a string.', $key));
        }

        return $data[$key];
    }
}
