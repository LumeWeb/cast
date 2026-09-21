<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Action Scheduler's OWN WP-Cron/loopback queue runner at the real boundary.
 *
 * Cast schedules exclusively through Action Scheduler, but deliberately leaves
 * Action Scheduler's own queue runner enabled (cast.php never disables it):
 * Action Scheduler decides how scheduled actions fire — normally its recurring
 * `action_scheduler_run_queue` WP-Cron event plus the loopback async dispatch.
 * These tests pin that contract against the real vendored library so a future
 * change cannot silently disable or dismantle the runner the queue depends on,
 * and so nobody mistakes AS's own WP-Cron runner for the vanilla WP-Cron
 * scheduling path Cast removed.
 */
final class ActionSchedulerRunnerBoundaryTest extends TestCase
{
    /**
     * The hook Action Scheduler's real QueueRunner ('action_scheduler_run_queue',
     * its public WP_CRON_HOOK constant) drives the queue through on its own
     * recurring WP-Cron event, scheduled with the context ['WP Cron'].
     */
    private const AS_RUNNER_HOOK = 'action_scheduler_run_queue';

    protected function setUp(): void
    {
        // Boot the plugin once per process (idempotent), which requires the
        // vendored Action Scheduler entry file before Cast boots.
        require_once dirname(__DIR__, 2) . '/cast.php';
    }

    public function testActionSchedulerIsLoadedAndInitialisedAtTheRealBoundary(): void
    {
        self::assertTrue(
            function_exists('as_schedule_single_action'),
            'The Action Scheduler as_* API must be registered once cast.php requires the library.',
        );
        self::assertTrue(
            class_exists('ActionScheduler', false),
            'The Action Scheduler runtime class must be loaded at the integration boundary.',
        );
        self::assertTrue(
            \ActionScheduler::is_initialized('ActionSchedulerRunnerBoundaryTest'),
            'Action Scheduler must have initialised its data store at the integration boundary.',
        );
    }

    public function testActionSchedulerOwnQueueRunnerIsRegisteredAndEnabled(): void
    {
        // The real QueueRunner registers its WP-Cron handler on this hook, and
        // Cast must never remove or disable it.
        self::assertNotFalse(
            has_action(self::AS_RUNNER_HOOK),
            'The Action Scheduler queue-runner hook must still have its handler registered (Cast must not dismantle AS\'s own runner).',
        );

        // The runner drives the queue through its own recurring WP-Cron event
        // (context ['WP Cron']) scheduled by AS's QueueRunner::init(); Cast
        // must not clear it. Running under real WordPress, this is exactly the
        // "Action Scheduler may be driven by WP-Cron/loopback" decision.
        self::assertNotFalse(
            wp_next_scheduled(self::AS_RUNNER_HOOK, ['WP Cron']),
            'The Action Scheduler recurring runner event must remain scheduled under its own WP-Cron runner.',
        );
    }

    public function testCastJobsLandInRealActionSchedulerStoreForItsRunner(): void
    {
        // A dedicated round-trip through the real as_* API with Cast's group:
        // a Cast job is queued for the runner in the AS store (never a
        // Cast-owned vanilla WP-Cron slot), observable and cancellable again.
        $actionId = as_schedule_single_action(
            time() + 3600,
            'cast/test/runner-boundary',
            [],
            'cast/export',
            true,
        );
        self::assertGreaterThan(0, $actionId, 'A real Action Scheduler schedule must return a positive action id.');
        self::assertTrue(
            as_has_scheduled_action('cast/test/runner-boundary', [], 'cast/export'),
            'The queued action must be visible to Action Scheduler\u2019s own runner.',
        );

        // Cleanup so the shared suite database stays deterministic.
        as_unschedule_all_actions('cast/test/runner-boundary', [], 'cast/export');
    }
}
