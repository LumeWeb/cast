<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Typed outcome of an explicit publish cancel.
 *
 * Either the live run was cancelled (cancelled=true, with its run id and all
 * pending publish events cleared) or it was refused with a typed
 * {@see PublishCancelRefusal}. The serialized form is JSON-safe: a refusal
 * never echoes internals and a run id is a non-secret identifier.
 */
final class PublishCancelResult
{
    public function __construct(
        public readonly bool $cancelled,
        public readonly ?PublishCancelRefusal $refusal = null,
        public readonly ?string $runId = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->cancelled) {
            return [
                'cancelled' => true,
                'status' => 'cancelled',
                'run_id' => $this->runId,
                'refusal' => null,
            ];
        }

        return [
            'cancelled' => false,
            'status' => 'refused',
            'run_id' => null,
            'refusal' => $this->refusal?->value,
        ];
    }
}
