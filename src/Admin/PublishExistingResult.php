<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Typed outcome of an explicit publish-existing retry.
 *
 * Either a publish-only run was seeded from an intact existing artifact and
 * queued (queued=true, with the fresh run id) or it was refused with a typed
 * {@see PublishExistingRefusal}. The serialized form is JSON-safe: a refusal
 * never echoes internals and a run id is a non-secret identifier.
 */
final class PublishExistingResult
{
    public function __construct(
        public readonly bool $queued,
        public readonly ?PublishExistingRefusal $refusal = null,
        public readonly ?string $runId = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->queued) {
            return [
                'queued' => true,
                'status' => 'queued',
                'run_id' => $this->runId,
                'refusal' => null,
            ];
        }

        return [
            'queued' => false,
            'status' => 'refused',
            'run_id' => null,
            'refusal' => $this->refusal?->value,
        ];
    }
}
