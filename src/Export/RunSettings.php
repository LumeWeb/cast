<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

use LumeWeb\Cast\Publish\Contract;

/**
 * Immutable snapshot of the settings an export/publish run started with.
 *
 * Every property is promoted readonly on a final class, so once constructed
 * the snapshot can never be changed. The aggregate captures this at start()
 * and holds it for the whole run — subsequent site configuration (or a later
 * call with different settings for a new run) can never leak into an already
 * executing run.
 *
 * The (de)serialization pair is explicit and typed so the future WordPress
 * adapter can round-trip a run row without guessing field shapes.
 *
 * targetType is an INTERNAL token, never a portal wire value. New runs default
 * to 'ipns' (Cast deliberately maintains an IPNS publication for every site),
 * while a
 * late-2026 legacy default of 'website' is back-compat-mapped to 'ipfs'
 * semantics on read so an already-IPFS-published run re-publishes exactly as
 * it always did. The wire mapping itself ('website'→'ipfs', 'ipns'→'ipns')
 * lives in IpfsWebsitesClient::wireTargetType().
 */
final class RunSettings
{
    public function __construct(
        public readonly string $targetType = 'ipns',
        public readonly string $hostname = '',
        public readonly string $artifactName = 'site',
        public readonly int $uploadLimitBytes = Contract::UPLOAD_LIMIT_BYTES,
        public readonly int $maxRetries = 3,
        public readonly string $startCursor = '',
    ) {
    }

    public static function fresh(): self
    {
        return new self();
    }

    /**
     * Explicit, validated serialization for a persisted run.
     *
     * @return array{
     *     target_type: string,
     *     hostname: string,
     *     artifact_name: string,
     *     upload_limit_bytes: int,
     *     max_retries: int,
     *     start_cursor: string
     * }
     */
    public function toArray(): array
    {
        return [
            'target_type' => $this->targetType,
            'hostname' => $this->hostname,
            'artifact_name' => $this->artifactName,
            'upload_limit_bytes' => $this->uploadLimitBytes,
            'max_retries' => $this->maxRetries,
            'start_cursor' => $this->startCursor,
        ];
    }

    /**
     * Rebuild a settings snapshot from persisted data. Unknown keys are
     * ignored; missing or wrongly-typed keys fall back to the documented
     * defaults.
     *
     * Legacy runs persisted before the ipns default map their stored 'website'
     * token to 'ipfs' here, so a re-publish of an already-IPFS-targeted site
     * keeps its IPFS targeting instead of silently flipping to IPNS.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $hostname = $data['hostname'] ?? null;
        $artifactName = $data['artifact_name'] ?? null;
        $uploadLimitBytes = $data['upload_limit_bytes'] ?? null;
        $maxRetries = $data['max_retries'] ?? null;
        $startCursor = $data['start_cursor'] ?? null;

        return new self(
            targetType: self::normalizeTargetType($data['target_type'] ?? null),
            hostname: is_string($hostname) ? $hostname : '',
            artifactName: is_string($artifactName) ? $artifactName : 'site',
            uploadLimitBytes: is_int($uploadLimitBytes) ? $uploadLimitBytes : Contract::UPLOAD_LIMIT_BYTES,
            maxRetries: is_int($maxRetries) ? $maxRetries : 3,
            startCursor: is_string($startCursor) ? $startCursor : '',
        );
    }

    /**
     * The internal target token for a persisted target_type value: any other
     * string passes through untouched, a legacy 'website' row becomes 'ipfs'
     * (the wire semantics 'website' always meant), and a missing/absent value
     * falls back to the new 'ipns' default.
     */
    private static function normalizeTargetType(mixed $value): string
    {
        if (!is_string($value)) {
            return 'ipns';
        }

        return $value === 'website' ? 'ipfs' : $value;
    }
}
