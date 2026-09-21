<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Jobs\PublishMode;

/**
 * Typed outcome of a publish-mode update.
 *
 * Either the mode was persisted (updated=true, with the new mode and its
 * auto-flag) or the change was refused with a typed {@see PublishModeRefusal},
 * in which case the `mode`/`auto_active` fields echo the unchanged current
 * mode. The serialized form is JSON-safe.
 */
final class PublishModeResult
{
    public function __construct(
        public readonly bool $updated,
        public readonly ?PublishMode $mode = null,
        public readonly ?PublishModeRefusal $refusal = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->updated) {
            return [
                'updated' => true,
                'status' => 'updated',
                'mode' => $this->mode?->value,
                'auto_active' => $this->mode?->isAutomatic() ?? false,
            ];
        }

        return [
            'updated' => false,
            'status' => 'refused',
            'refusal' => $this->refusal?->value,
            'mode' => $this->mode?->value,
            'auto_active' => $this->mode?->isAutomatic() ?? false,
        ];
    }
}
