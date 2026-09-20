<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

use LumeWeb\Cast\Export\ExportRun;

/**
 * One bounded unit of export/publish work, injected into
 * {@see ExportTickRunner}. It mutates the given {@see ExportRun} aggregate and
 * reports a {@see TickResult}; the runner owns persistence, lock lifecycle and
 * retry bookkeeping around it. The real export/upload/publish steps are
 * composed outside this module.
 */
interface BoundTick
{
    public function perform(ExportRun $run, int $now): TickResult;
}
