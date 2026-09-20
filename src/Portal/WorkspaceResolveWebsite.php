<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Portal;

use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * The attached Website publish relationship returned inside the portal
 * Workspaces.Resolve response (WorkspaceResolveWebsite). Immutable and minimal:
 * id, lifecycle status and the target it points at. The status value matches
 * the website lifecycle the publish stack reads (STATUS_LIVE === 'active').
 */
final class WorkspaceResolveWebsite
{
    /**
     * The website status value that means the site is actually serving — the
     * same status the publish readiness wait confirms before a deploy is
     * advertised as live.
     */
    public const STATUS_LIVE = 'active';

    private function __construct(
        private readonly int $id,
        private readonly string $status,
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
            throw new ResponseDecodingException('Workspace resolve website response is not a JSON object.');
        }

        return new self(
            self::intField($data, 'id'),
            self::stringField($data, 'status'),
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

    public function targetHash(): string
    {
        return $this->targetHash;
    }

    public function targetType(): string
    {
        return $this->targetType;
    }

    /**
     * Whether the attached website is serving live content (the same value the
     * publish readiness wait confirms).
     */
    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    /**
     * @param array<mixed> $data
     */
    private static function intField(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new ResponseDecodingException(sprintf('Workspace resolve website response is missing integer field "%s".', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new ResponseDecodingException(sprintf('Workspace resolve website response is missing string field "%s".', $key));
        }

        return $data[$key];
    }
}
