<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Persistence;

use RuntimeException;

/**
 * The persisted export run could not be read back as a valid aggregate.
 *
 * Raised (instead of silently returning a fresh run) so callers can tell a
 * genuinely missing option apart from a corrupt one and, at their discretion,
 * surface or recover from it. The message never echoes the stored value, so a
 * corrupt option cannot leak its contents.
 */
final class RunPersistenceException extends RuntimeException
{
}
