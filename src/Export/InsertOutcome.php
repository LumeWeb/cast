<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * What insertCanonical() decided for a candidate: a brand-new row was created,
 * an existing row's priority was replaced, or the duplicate was ignored.
 */
final class InsertOutcome
{
    private function __construct(
        private readonly bool $inserted,
        private readonly bool $priorityUpdated,
    ) {
    }

    public static function created(): self
    {
        return new self(true, false);
    }

    public static function priorityReplaced(): self
    {
        return new self(false, true);
    }

    public static function duplicate(): self
    {
        return new self(false, false);
    }

    public function inserted(): bool
    {
        return $this->inserted;
    }

    public function priorityUpdated(): bool
    {
        return $this->priorityUpdated;
    }

    public function duplicateIgnored(): bool
    {
        return !$this->inserted && !$this->priorityUpdated;
    }
}
