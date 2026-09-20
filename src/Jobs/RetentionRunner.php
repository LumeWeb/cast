<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

use Closure;
use LumeWeb\Cast\Export\ArtifactRetentionService;
use LumeWeb\Cast\Export\RetentionPolicy;
use LumeWeb\Cast\Export\RetentionSummary;

/**
 * The WP-Cron retention handler behind {@see RetentionScheduler::RETENTION_HOOK}.
 *
 * Each invocation runs the pure {@see ArtifactRetentionService} against the
 * policy the composition's option reader produced (live at sweep time, so a
 * changed `cast_publish_retention_days` takes effect without a reboot) and
 * then re-arms the next periodic sweep through the deduplicated scheduler. The
 * periodic re-arm runs even when the current policy is disabled, so the option
 * is re-read once a day and an admin re-enabling retention never needs a new
 * terminal run to restart GC.
 */
final class RetentionRunner
{
    /**
     * @param Closure(): RetentionPolicy $policyFactory live policy source; the
     *                                                  WordPress composition
     *                                                  reads the option here
     */
    public function __construct(
        private readonly ArtifactRetentionService $service,
        private readonly RetentionScheduler $scheduler,
        private readonly Clock $clock,
        private readonly Closure $policyFactory,
    ) {
    }

    public function run(?int $at = null): RetentionSummary
    {
        $now = $at ?? $this->clock->now();
        $policy = ($this->policyFactory)();

        $summary = $this->service->collect($now, $policy);

        // Always re-arm the periodic sweep (deduplicated) so retention keeps
        // running on a quiet site and a single terminal arming can never
        // accidentally switch future GC off.
        $this->scheduler->armPeriodic($now);

        return $summary;
    }
}
