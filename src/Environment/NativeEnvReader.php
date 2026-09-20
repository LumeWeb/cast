<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Environment;

/**
 * EnvReader backed by the real process environment via getenv(), matching the
 * plan's "read per run, held in memory only" lifecycle: one snapshot per read,
 * never persisted and never logged here.
 */
final class NativeEnvReader implements EnvReader
{
    public function get(string $name): string|false
    {
        return getenv($name);
    }
}
