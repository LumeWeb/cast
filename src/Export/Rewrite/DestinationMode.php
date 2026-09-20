<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

/**
 * Destination mode for this rewrite unit.
 *
 * v1 ships only the `offline-zip` mode: the portal extracts the ZIP and may
 * mount it anywhere, so every internal reference becomes a './' relative path
 * between deterministic output files. Any other mode name is rejected.
 */
enum DestinationMode: string
{
    case OfflineZip = 'offline-zip';

    /**
     * Accept the mode strings used across the design docs ('offline-zip',
     * 'offline', 'zip') and reject everything else.
     */
    public static function fromString(string $mode): self
    {
        return match (strtolower(trim($mode))) {
            'offline-zip', 'offline', 'zip' => self::OfflineZip,
            default => throw new \InvalidArgumentException(
                "Unsupported destination mode '{$mode}'; only offline-zip is available in this slice."
            ),
        };
    }
}
