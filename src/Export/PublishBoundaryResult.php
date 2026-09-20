<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

use InvalidArgumentException;

/**
 * The persisted outcome of the publish pipeline boundary: whether the publish
 * completed, failed or stalled resumable, together with the CID/website/IPNS
 * identity produced so far and a plain-language message.
 *
 * Mirrors the other per-stage result DTOs so {@see ExportRun} can carry it
 * across WP-Cron requests and rehydrate the shared {@see PipelineState}
 * without re-publishing. A resumable outcome preserves any identity already
 * owned so the durable PublishRegistry-backed retry continues exactly where
 * the publish stalled.
 */
final class PublishBoundaryResult
{
    public function __construct(
        public readonly PublishBoundaryStatus $status,
        public readonly ?string $cid = null,
        public readonly ?string $websiteId = null,
        public readonly ?string $ipnsKey = null,
        public readonly ?string $message = null,
    ) {
    }

    public static function completed(string $cid, string $websiteId, string $ipnsKey): self
    {
        return new self(PublishBoundaryStatus::Completed, $cid, $websiteId, $ipnsKey, null);
    }

    public static function failed(string $message): self
    {
        return new self(PublishBoundaryStatus::Failed, null, null, null, $message);
    }

    public static function resumable(string $cid, ?string $websiteId, ?string $ipnsKey, string $message): self
    {
        return new self(PublishBoundaryStatus::Resumable, $cid, $websiteId, $ipnsKey, $message);
    }

    public function isSuccess(): bool
    {
        return $this->status === PublishBoundaryStatus::Completed;
    }

    /**
     * Explicit, validated serialization for a persisted boundary result.
     *
     * @return array{
     *     status: string,
     *     cid: string|null,
     *     website_id: string|null,
     *     ipns_key: string|null,
     *     message: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'cid' => $this->cid,
            'website_id' => $this->websiteId,
            'ipns_key' => $this->ipnsKey,
            'message' => $this->message,
        ];
    }

    /**
     * Rebuild a boundary result from persisted data.
     *
     * A malformed status or a non-string nullable field is rejected loudly so
     * a corrupt row is never silently reinterpreted.
     *
     * @param mixed $data The raw persisted value.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new InvalidArgumentException('Publish boundary result is not an array.');
        }

        $status = $data['status'] ?? null;
        if (!is_string($status) || PublishBoundaryStatus::tryFrom($status) === null) {
            throw new InvalidArgumentException('Publish boundary result status is malformed.');
        }

        $cid = $data['cid'] ?? null;
        if ($cid !== null && !is_string($cid)) {
            throw new InvalidArgumentException('Publish boundary result cid is malformed.');
        }
        $websiteId = $data['website_id'] ?? null;
        if ($websiteId !== null && !is_string($websiteId)) {
            throw new InvalidArgumentException('Publish boundary result website_id is malformed.');
        }
        $ipnsKey = $data['ipns_key'] ?? null;
        if ($ipnsKey !== null && !is_string($ipnsKey)) {
            throw new InvalidArgumentException('Publish boundary result ipns_key is malformed.');
        }
        $message = $data['message'] ?? null;
        if ($message !== null && !is_string($message)) {
            throw new InvalidArgumentException('Publish boundary result message is malformed.');
        }

        return new self(PublishBoundaryStatus::from($status), $cid, $websiteId, $ipnsKey, $message);
    }
}
