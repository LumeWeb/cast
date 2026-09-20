<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

/**
 * In-memory WarningCollector used by the rewrite service and asserted in tests.
 */
final class ArrayWarningCollector implements WarningCollector
{
    /**
     * @var list<array{category: string, message: string}>
     */
    private array $warnings = [];

    public function record(string $category, string $message): void
    {
        $this->warnings[] = ['category' => $category, 'message' => $message];
    }

    /**
     * @return list<array{category: string, message: string}>
     */
    public function all(): array
    {
        return $this->warnings;
    }

    public function isEmpty(): bool
    {
        return $this->warnings === [];
    }

    public function hasLeftoverOrigin(): bool
    {
        foreach ($this->warnings as $warning) {
            if ($warning['category'] === self::ORIGIN_LEFTOVER) {
                return true;
            }
        }

        return false;
    }
}
