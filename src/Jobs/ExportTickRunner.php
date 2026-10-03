<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Jobs;

use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\RunRepository;
use LumeWeb\Cast\Export\RunStatus;

/**
 * The WP-Cron-safe driver for one export tick.
 *
 * Exactly one worker may run at a time (a keyed lease with a TTL), the current
 * run is loaded fresh from the repository, a bounded batch of consecutive
 * injected tick units executes, every mutation is persisted after each unit,
 * and the lease is released on success, on failure and even when a unit
 * throws. The batch is bounded by two independent caps:
 *  - a wall-clock TIME BUDGET ({@see TickConfig::$timeBudgetSeconds}, 30s by
 *    default) measured with the injected {@see Clock} — once the elapsed
 *    time reaches it, no next unit starts, so a slow unit (e.g. a 30s HTTP
 *    capture) cannot push the tick past the 60s lock TTL; and
 *  - an item HARD CAP ({@see TickConfig::$unitsPerTick}, 20 by default) on
 *    the number of units per tick.
 * The batch also stops early the moment a unit finishes the run, fails it,
 * signals the watchdog, or leaves the run paused/terminal. Lock contention,
 * stale leases and the readiness check (no auto-start before content/identity
 * exists) all no-op safely without touching the run.
 *
 * The watchdog/reclaim decision boundary is expressed purely as an injected
 * tick result ({@see TickResult::stale()}) — no SQL is needed in this module.
 * No credentials ever pass through here.
 */
final class ExportTickRunner
{
    public function __construct(
        private Clock $clock,
        private Lock $lock,
        private RunRepository $repository,
        private BoundTick $tick,
        private IdentityGateway $identity,
        private TickConfig $config = new TickConfig(),
    ) {
    }

    public function tick(?int $at = null): TickOutcome
    {
        $now = $at ?? $this->clock->now();

        $expiresAt = $this->lock->leaseExpiresAt($this->config->lockKey);
        if ($expiresAt !== null && $expiresAt > $now) {
            return TickOutcome::skippedLocked();
        }
        if ($expiresAt !== null && $expiresAt <= $now && !$this->config->reclaimStaleLocks) {
            return TickOutcome::skippedStaleLock();
        }

        if (!$this->lock->acquire($this->config->lockKey, $this->config->lockTtlSeconds)) {
            return TickOutcome::skippedLocked();
        }

        try {
            return $this->runLocked($now, $at);
        } finally {
            $this->lock->release($this->config->lockKey);
        }
    }

    private function runLocked(int $now, ?int $at): TickOutcome
    {
        $run = $this->repository->latest();
        if ($run === null) {
            return TickOutcome::skippedNoRun();
        }

        // An overtaken run may only be cancelled — stop it cleanly.
        if ($run->superseded && !$run->isTerminal()) {
            $run->cancel(at: $now);
            $this->repository->save($run);

            return TickOutcome::cancelled();
        }

        if ($run->isTerminal() || $run->status === RunStatus::Paused) {
            return TickOutcome::skippedNotReady();
        }

        // Pending dirty runs auto-start once a publishable identity exists; a
        // run the user explicitly started (Publish now / first publish) may
        // begin before that. Any other pending queued run is deferred — the
        // worker reports Deferred so the subscriber keeps a tick armed and
        // the run progresses the moment identity (or an explicit start) shows
        // up, instead of silently orphaning a queued run with no scheduled
        // event and no cause.
        if ($run->status === RunStatus::NotStarted) {
            if (!$run->dirty) {
                return TickOutcome::skippedNotReady();
            }

            if (!$this->identity->hasIdentity() && !$run->explicitStart) {
                return TickOutcome::deferred();
            }

            $run->start(at: $now);
            $this->repository->save($run);
        }

        // A bounded batch of consecutive pipeline units under the same single
        // global lease: at most $maxUnits units (item hard cap) and no more
        // elapsed wall-clock time than the tick's time budget, persisted
        // after each unit. The moment any unit leaves the run finished,
        // failed, stale (watchdog) or paused/terminal the batch stops and
        // the tick reports that boundary — the rest of the work waits for
        // the next scheduled tick exactly as a single-unit tick would.
        $maxUnits = max(1, $this->config->unitsPerTick);
        $deadline = $now + $this->config->timeBudgetSeconds;
        for ($unit = 0; $unit < $maxUnits; $unit++) {
            // Each unit sees the current wall-clock instant (or the explicit
            // $at the caller pinned for this tick) so progress timestamps
            // reflect real elapsed time across the batch.
            $unitNow = $at ?? $this->clock->now();
            $result = $this->tick->perform($run, $unitNow);
            $this->repository->save($run);

            if ($result->stale) {
                return TickOutcome::watchdog();
            }

            if ($result->failure !== null) {
                return $this->recordFailure($run, $result->failure, $now);
            }

            if ($result->finished) {
                return TickOutcome::completed();
            }

            // A unit may also park the run (paused / a terminal state reached
            // inside the pipeline): never burn the remaining budget on a run
            // that no longer advances.
            if ($run->isTerminal() || $run->status === RunStatus::Paused) {
                return TickOutcome::ran();
            }

            // Time budget: the elapsed wall-clock time already reached it,
            // so no next unit may start — a slow unit (e.g. a 30s HTTP
            // capture) stops here instead of stretching the single global
            // lease toward its TTL.
            if ($this->clock->now() >= $deadline) {
                return TickOutcome::ran();
            }
        }

        return TickOutcome::ran();
    }

    private function recordFailure(ExportRun $run, string $reason, int $now): TickOutcome
    {
        if ($run->retriesExhausted()) {
            $run->fail($reason, at: $now);
            $this->repository->save($run);

            return TickOutcome::failed($reason);
        }

        $run->recordRetry(at: $now);
        $run->lastError = $reason;
        $this->repository->save($run);

        return TickOutcome::retried();
    }
}
