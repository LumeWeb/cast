<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

use RuntimeException;

/**
 * Action Scheduler declined or was unavailable for a scheduling attempt.
 *
 * Cast schedules exclusively through Action Scheduler. When the runtime cannot
 * accept a job (the Action Scheduler library is not loaded, its store is not
 * initialised, or it refused the insert), pretending the run was "Scheduled"
 * would silently strand the site. Instead the caller surfaces this actionable
 * failure so an operator can verify the dependency before any run is queued.
 */
final class SchedulingFailedException extends RuntimeException
{
}
