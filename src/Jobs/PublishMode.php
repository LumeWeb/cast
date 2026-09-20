<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * The user-selected publish trigger mode.
 *
 * Manual (the default) publishes only on explicit actions — saves never
 * schedule anything. On-update publishes automatically on publish-relevant
 * content transitions.
 *
 * The only two selectable modes are Manual and On-update. A legacy persisted
 * value of `scheduled` (a reserved placeholder for a never-built WP-Cron
 * recurrence that auto-scheduled exactly like On-update) migrates to
 * On-update on read so an existing site keeps the auto-publishing behavior it
 * was already relying on instead of silently flipping to Manual.
 *
 * Unknown or missing persisted values normalize to Manual so a corrupt option
 * can never silently flip a site into auto-publishing.
 */
enum PublishMode: string
{
    case Manual = 'manual';

    case OnUpdate = 'on_update';

    /**
     * The legacy value that used to be persisted before Scheduled was retired
     * from the UX. It is not a selectable mode; {@see fromStored()} migrates it
     * to On-update to keep the auto-scheduling contract it already had.
     */
    private const LEGACY_SCHEDULED_VALUE = 'scheduled';

    /**
     * The manual-first default applied when no mode was ever chosen.
     */
    public static function default(): self
    {
        return self::Manual;
    }

    /**
     * Whether automatic scheduling is enabled by this mode (anything but
     * Manual). Manual explicitly refuses to auto-schedule.
     */
    public function isAutomatic(): bool
    {
        return $this !== self::Manual;
    }

    /**
     * Normalize a persisted value back to a known mode, defaulting to Manual.
     *
     * The retired `scheduled` value is migrated to On-update (its historical
     * auto-scheduling behavior); anything else unknown or missing falls back
     * to Manual.
     */
    public static function fromStored(mixed $value): self
    {
        if (is_string($value)) {
            if ($value === self::LEGACY_SCHEDULED_VALUE) {
                return self::OnUpdate;
            }

            foreach (self::cases() as $mode) {
                if ($mode->value === $value) {
                    return $mode;
                }
            }
        }

        return self::Manual;
    }
}
