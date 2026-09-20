<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * The typed, non-credential publish destination identity: the portal website
 * plus the IPNS key used to publish under it, and whether the pair is ready to
 * accept auto-exports.
 *
 * This value object never carries credentials — it holds only identifiers
 * (website id/name, IPNS key id/name) that are safe to persist in a
 * non-autoloaded option. `fromOptionValue()` returns null for anything
 * missing, unknown-schema or corrupt so callers can treat it as "no identity
 * yet" instead of misreading partial storage.
 */
final class PublishIdentity
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public readonly string $websiteId,
        public readonly string $websiteName,
        public readonly string $ipnsKeyId,
        public readonly string $ipnsKeyName,
        private readonly bool $ready = false,
    ) {
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    /**
     * @return array{
     *     schema_version: int,
     *     ready: bool,
     *     website: array{id: string, name: string},
     *     ipns_key: array{id: string, name: string}
     * }
     */
    public function toOptionValue(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ready' => $this->ready,
            'website' => [
                'id' => $this->websiteId,
                'name' => $this->websiteName,
            ],
            'ipns_key' => [
                'id' => $this->ipnsKeyId,
                'name' => $this->ipnsKeyName,
            ],
        ];
    }

    /**
     * Rebuild an identity from the stored option value, or null when the value
     * is missing, unknown-schema, or corrupt in any required field.
     *
     * @param mixed $value
     */
    public static function fromOptionValue(mixed $value): ?self
    {
        if (!is_array($value) || ($value['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return null;
        }

        $website = $value['website'] ?? null;
        if (!is_array($website)) {
            return null;
        }

        $websiteId = $website['id'] ?? null;
        $websiteName = $website['name'] ?? null;
        if (!is_string($websiteId) || $websiteId === '' || !is_string($websiteName) || $websiteName === '') {
            return null;
        }

        $ipns = $value['ipns_key'] ?? null;
        if (!is_array($ipns)) {
            return null;
        }

        $ipnsId = $ipns['id'] ?? null;
        $ipnsName = $ipns['name'] ?? null;
        if (!is_string($ipnsId) || $ipnsId === '' || !is_string($ipnsName) || $ipnsName === '') {
            return null;
        }

        return new self(
            websiteId: $websiteId,
            websiteName: $websiteName,
            ipnsKeyId: $ipnsId,
            ipnsKeyName: $ipnsName,
            ready: ($value['ready'] ?? false) === true,
        );
    }
}
