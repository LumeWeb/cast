<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

/**
 * Typed outcome of the manual-run-wins {@see ContentPublishScheduler::startNow()}
 * primitive.
 *
 * `started` is true when a run was scheduled to tick immediately — either the
 * queued NotStarted run was absorbed, or a fresh dirty run was created. It is
 * false (with a null run id) only when the latest run is actively executing or
 * superseded, i.e. there is nothing to fold and a second stacked run must be
 * refused.
 */
final class PublishNowResult
{
    public function __construct(
        public readonly bool $started,
        public readonly ?string $runId = null,
    ) {
    }
}
