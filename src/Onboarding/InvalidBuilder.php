<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Onboarding;

use RuntimeException;

/**
 * Raised when a builder-selection request names a slug that is not on the
 * catalog allowlist (or is not installable). The caller must never invent a
 * candidate for an arbitrary slug.
 */
final class InvalidBuilder extends RuntimeException
{
}
