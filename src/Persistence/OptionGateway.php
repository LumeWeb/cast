<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Persistence;

/**
 * Minimal option-table wrapper isolating WordPress option functions behind an
 * interface, so persistence code is testable without loading WordPress and
 * without redefining global functions in unit tests.
 *
 * add() must be atomic, mirroring add_option(): it only stores the value when
 * the option does not already exist and reports whether it did, which is what
 * the run repository's create-time check relies on.
 */
interface OptionGateway
{
    public function get(string $option, mixed $default): mixed;

    public function add(string $option, mixed $value, bool $autoload): bool;

    public function update(string $option, mixed $value, bool $autoload): bool;

    public function delete(string $option): bool;
}
