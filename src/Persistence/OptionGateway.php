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
 * the run repository's create-time check relies on. updateIfEquals() must be
 * atomic the other way round: it only overwrites a value that is currently
 * still exactly $expected, reporting whether the write happened, so a caller
 * can reclaim a stale value with a compare-and-set instead of a blind update.
 */
interface OptionGateway
{
    public function get(string $option, mixed $default): mixed;

    public function add(string $option, mixed $value, bool $autoload): bool;

    public function update(string $option, mixed $value, bool $autoload): bool;

    public function delete(string $option): bool;

    /**
     * Overwrite the option atomically only when it currently holds exactly
     * $expected, reporting whether the write landed. A concurrent writer that
     * changed the value since it was observed makes this a no-op (false), so
     * racing reclaims keep the exactly-one-winner guarantee of add().
     */
    public function updateIfEquals(string $option, mixed $value, mixed $expected): bool;
}
