<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Typed outcome of an explicit first-publish start.
 *
 * Either the run was queued (queued=true, with the fresh run id) or it was
 * refused with a typed {@see PublishStartRefusal}. The serialized form is
 * JSON-safe and never echoes a refusal reason that could leak internals.
 */
final class PublishStartResult
{
    public function __construct(
        public readonly bool $queued,
        public readonly ?PublishStartRefusal $refusal = null,
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
