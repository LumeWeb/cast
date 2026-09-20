<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

use RuntimeException;

/**
 * A requested run lifecycle/stage move is not legal for the run's current
 * status (e.g. completing before start, resuming while running, or letting a
 * superseded run finish). Mirrors the onboarding InvalidTransition pattern so
 * callers handle one typed exception.
 */
final class InvalidTransition extends RuntimeException
{
}
