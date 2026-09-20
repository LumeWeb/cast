<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * In-memory PublishModeStore used by tests and single-process runtimes.
 *
 * Defaults to {@see PublishMode::Manual} and holds the mode for the process
 * lifetime, mirroring the persisted store's default without any option I/O.
 */
final class InMemoryPublishModeStore implements PublishModeStore
{
    private PublishMode $mode;

    public function __construct(?PublishMode $mode = null)
    {
        $this->mode = $mode ?? PublishMode::default();
    }

    public function mode(): PublishMode
    {
        return $this->mode;
    }

    public function setMode(PublishMode $mode): void
    {
        $this->mode = $mode;
    }
}
