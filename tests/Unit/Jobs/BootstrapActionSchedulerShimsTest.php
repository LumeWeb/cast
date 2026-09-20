<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;

/**
 * Pins the Action Scheduler test-shim contract the unit bootstrap provides.
 *
 * The bootstrap replaces Cast's former WP-Cron single-event shims with an
 * in-memory Action Scheduler shim (the as_* 4.2.0 public API backed by the
 * `lumeweb_cast_actions` store) so the BOOTED production composition
 * (CastPlugin → WordPressActionScheduler → WordPressActionSchedulerGateway →
 * as_* functions) stays exercisable end to end without loading the real
 * library. This test guards the shim's identity semantics — (group, hook,
 * args) keying, unique scheduling, pending-vs-running reporting and the
 * documented uninitialized boundary (0 / false / null / no-op) — so the
 * booted ordering assertions in the wiring suites remain meaningful.
 */
final class BootstrapActionSchedulerShimsTest extends TestCase
{
    protected function setUp(): void
    {
        self::assertTrue(
            function_exists('as_schedule_single_action'),
            'The unit bootstrap must provide the Action Scheduler shim API.',
        );
        $this->resetActions();
    }

    protected function tearDown(): void
    {
        $this->resetActions();
    }

    public function testShimSchedulesAUniqueActionAndReportsItsTimestamp(): void
    {
        $id = as_schedule_single_action(1700, 'cast/export/auto-tick', [], 'cast/export', true, 10);

        self::assertGreaterThan(0, $id);
        self::assertSame(1700, as_next_scheduled_action('cast/export/auto-tick', [], 'cast/export'));
        self::assertTrue(as_has_scheduled_action('cast/export/auto-tick', [], 'cast/export'));
    }

    public function testShimDedupesUniqueActionsByHookArgsGroup(): void
    {
        as_schedule_single_action(1700, 'cast/export/auto-tick', [], 'cast/export', true, 10);

        // A second unique schedule for the same (hook, args, group) is refused
        // without moving the original timestamp.
        self::assertSame(0, as_schedule_single_action(1800, 'cast/export/auto-tick', [], 'cast/export', true, 10));
        self::assertSame(1700, as_next_scheduled_action('cast/export/auto-tick', [], 'cast/export'));

        // A different group or a different hook is an independent slot.
        self::assertGreaterThan(0, as_schedule_single_action(999, 'cast/export/auto-tick', [], 'other', true, 10));
        self::assertGreaterThan(0, as_schedule_single_action(999, 'cast/export/other', [], 'cast/export', true, 10));
    }

    public function testShimUnscheduleAllOnlyCancelsPendingMatches(): void
    {
        as_schedule_single_action(1700, 'cast/export/auto-tick', [], 'cast/export', true, 10);

        as_unschedule_all_actions('cast/export/auto-tick', [], 'cast/export');

        self::assertFalse(as_next_scheduled_action('cast/export/auto-tick', [], 'cast/export'));
        self::assertFalse(as_has_scheduled_action('cast/export/auto-tick', [], 'cast/export'));
        // Unrelated groups and hooks are untouched.
        as_schedule_single_action(999, 'cast/export/other', [], 'cast/export', true, 10);
        self::assertSame(999, as_next_scheduled_action('cast/export/other', [], 'cast/export'));

        // UnscheduleAll is a safe no-op when nothing matched.
        as_unschedule_all_actions('cast/export/auto-tick', [], 'cast/export');
    }

    public function testShimUnscheduleActionConcludesThePendingAction(): void
    {
        $id = as_schedule_single_action(1700, 'cast/export/auto-tick', [], 'cast/export', true, 10);
        self::assertSame($id, as_unschedule_action('cast/export/auto-tick', [], 'cast/export'));
        self::assertNull(as_unschedule_action('cast/export/auto-tick', [], 'cast/export'));
        self::assertFalse(as_next_scheduled_action('cast/export/auto-tick', [], 'cast/export'));
    }

    public function testShimReportsTrueWhileAnActionIsRunning(): void
    {
        $id = as_schedule_single_action(1, 'cast/export/auto-tick', [], 'cast/export', true, 10);
        self::assertGreaterThan(0, $id);

        // Mark the (group, hook, args) slot as in-flight, as Action Scheduler
        // does when a runner claims it: nextScheduled reports true (no
        // timestamp known) and hasScheduled stays true while it runs.
        $store = &$GLOBALS['lumeweb_cast_actions'];
        $key = $this->key('cast/export/auto-tick', [], 'cast/export');
        $entry = $store['actions'][$key];
        unset($store['actions'][$key]);
        $store['running'][$key] = $entry;

        self::assertTrue(as_next_scheduled_action('cast/export/auto-tick', [], 'cast/export'));
        self::assertTrue(as_has_scheduled_action('cast/export/auto-tick', [], 'cast/export'));
        // A unique schedule is refused while the slot is occupied.
        self::assertSame(0, as_schedule_single_action(2, 'cast/export/auto-tick', [], 'cast/export', true, 10));
    }

    public function testShimMatchesAnyArgsWhenArgsAreNull(): void
    {
        as_schedule_single_action(1700, 'cast/export/auto-tick', [3], 'cast/export', true, 10);

        // as_next_scheduled_action(..., null, ...) matches any args (mirrors
        // the real store's null-args search).
        self::assertSame(1700, as_next_scheduled_action('cast/export/auto-tick', null, 'cast/export'));
        self::assertTrue(as_has_scheduled_action('cast/export/auto-tick', null, 'cast/export'));
    }

    /**
     * @param list<mixed> $args
     */
    private function key(string $hook, array $args, string $group): string
    {
        return $group . '|' . $hook . '|' . serialize($args);
    }

    private function resetActions(): void
    {
        $GLOBALS['lumeweb_cast_actions'] = [
            'actions' => [],
            'running' => [],
            'last_id' => 0,
            'available' => true,
        ];
    }
}
