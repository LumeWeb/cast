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
 * run is loaded fresh from the repository, one injected bounded tick executes,
 * every mutation is persisted as it happens, and the lease is released on
 * success, on failure and even when the tick throws. Lock contention, stale
 * leases and the readiness check (no auto-start before content/identity exists)
 * all no-op safely without touching the run.
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
            return $this->runLocked($now);
        } finally {
            $this->lock->release($this->config->lockKey);
        }
    }

    private function runLocked(int $now): TickOutcome
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

        $result = $this->tick->perform($run, $now);
        $this->repository->save($run);

        if ($result->stale) {
            return TickOutcome::watchdog();
        }

        if ($result->failure !== null) {
            return $this->recordFailure($run, $result->failure, $now);
        }

        return $result->finished ? TickOutcome::completed() : TickOutcome::ran();
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
