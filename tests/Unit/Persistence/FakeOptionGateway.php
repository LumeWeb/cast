<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Persistence;

use LumeWeb\Cast\Persistence\OptionGateway;

/**
 * In-memory OptionGateway for persistence unit tests.
 *
 * Mirrors WordPress option semantics, including add_option()'s atomic
 * behaviour: add() only stores a value when the option is absent and reports
 * whether it did, so tests can exercise the adapter's create check without a
 * database or redefining global functions.
 */
final class FakeOptionGateway implements OptionGateway
{
    /**
     * @var array<string, mixed>
     */
    public array $options = [];

    /**
     * @var array<string, bool>
     */
    public array $autoload = [];

    public int $addCalls = 0;

    public int $updateCalls = 0;

    public int $updateIfEqualsCalls = 0;

    public int $deleteCalls = 0;

    public function get(string $option, mixed $default): mixed
    {
        return array_key_exists($option, $this->options) ? $this->options[$option] : $default;
    }

    public function add(string $option, mixed $value, bool $autoload): bool
    {
        ++$this->addCalls;

        if (array_key_exists($option, $this->options)) {
            return false;
        }

        $this->options[$option] = $value;
        $this->autoload[$option] = $autoload;

        return true;
    }

    public function update(string $option, mixed $value, bool $autoload): bool
    {
        ++$this->updateCalls;
        $this->options[$option] = $value;
        $this->autoload[$option] = $autoload;

        return true;
    }

    public function delete(string $option): bool
    {
        ++$this->deleteCalls;
        $existed = array_key_exists($option, $this->options);
        unset($this->options[$option], $this->autoload[$option]);

        return $existed;
    }

    public function updateIfEquals(string $option, mixed $value, mixed $expected): bool
    {
        ++$this->updateIfEqualsCalls;

        // Atomic compare-and-set: only overwrite while the CURRENT stored value
        // still equals exactly what the caller observed, mirroring the single
        // conditional UPDATE of the real gateway. A value changed in between
        // (another worker's claim or reclaim) makes this a no-op.
        if (!array_key_exists($option, $this->options) || $this->options[$option] !== $expected) {
            return false;
        }

        $this->options[$option] = $value;
        $this->autoload[$option] = false;

        return true;
    }
}
