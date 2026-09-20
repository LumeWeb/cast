<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Environment;

/**
 * Injectable read-only view of the process environment. Returns the raw value
 * as a string when the variable is set, or false when it is not set at all,
 * so callers can distinguish "missing" from "set but empty".
 */
interface EnvReader
{
    /**
     * @return string|false the raw environment value, or false when unset
     */
    public function get(string $name): string|false;
}
