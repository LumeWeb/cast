<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use Closure;
use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Jobs\BoundTick;
use LumeWeb\Cast\Jobs\TickResult;
use RuntimeException;

/**
 * Recording BoundTick double: counts invocations, remembers the run/instant it
 * was given, optionally mutates the run, optionally throws, and returns a
 * configurable TickResult (more() unless told otherwise).
 */
final class FakeTick implements BoundTick
{
    public int $calls = 0;

    public ?string $lastRunId = null;

    public ?int $lastNow = null;

    public ?TickResult $result = null;

    /**
     * @var Closure(ExportRun):void|null
     */
    public ?Closure $mutate = null;

    public ?RuntimeException $throw = null;

    public function perform(ExportRun $run, int $now): TickResult
    {
        ++$this->calls;
        $this->lastRunId = $run->runId;
        $this->lastNow = $now;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        if ($this->mutate !== null) {
            ($this->mutate)($run);
        }

        return $this->result ?? TickResult::more();
    }
}
