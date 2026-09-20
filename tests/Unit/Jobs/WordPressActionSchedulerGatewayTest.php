<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Jobs\WordPressActionSchedulerGateway;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the integration boundary against the Action Scheduler test shims
 * the unit bootstrap provides.
 *
 * The guarded gateway is available whenever the complete as_* 4.2.0 API is
 * present, and then delegates every operation with the exact (hook, args,
 * group) identity to the running Action Scheduler store. The bootstrap's shims
 * emulate Action Scheduler's documented uninitialized boundary (0 / false /
 * null / no-op) when the runtime is not initialised, so the gateway's pass-
 * through of that boundary stays observable under test without loading the
 * real library. The real library's own semantics — including its dedicated
 * WP-Cron queue runner, which Cast must not disable — are exercised against
 * the actual woocommerce/action-scheduler runtime at the WordPress
 * integration boundary.
 */
final class WordPressActionSchedulerGatewayTest extends TestCase
{
    private const HOOK = 'cast/export/auto-tick';

    private WordPressActionSchedulerGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new WordPressActionSchedulerGateway();
        $this->resetActions();
    }

    protected function tearDown(): void
    {
        $this->resetActions();
    }

    public function testGatewayIsAvailableWhenTheFullActionSchedulerApiIsPresent(): void
    {
        // The unit bootstrap ships the complete as_* shim API, so the gateway
        // reports the runtime available.
        self::assertTrue($this->gateway->isAvailable());
    }

    public function testGatewayForwardsAScheduleWithGroupIdentity(): void
    {
        $id = $this->gateway->scheduleSingle(
            1700,
            self::HOOK,
            [],
            'cast/export',
            unique: true,
        );

        self::assertGreaterThan(0, $id);
        self::assertSame(1700, as_next_scheduled_action(self::HOOK, [], 'cast/export'));
        self::assertTrue($this->gateway->hasScheduled(self::HOOK, [], 'cast/export'));
    }

    public function testGatewayNextScheduledMirrorsTheStore(): void
    {
        $this->gateway->scheduleSingle(1700, self::HOOK, [], 'cast/export');

        self::assertSame(1700, $this->gateway->nextScheduled(self::HOOK, [], 'cast/export'));
        // A different group is a different slot, exactly like the real store.
        self::assertFalse($this->gateway->nextScheduled(self::HOOK, [], 'other'));
    }

    public function testGatewayUnscheduleActionCancelsExactlyThePendingMatch(): void
    {
        $id = $this->gateway->scheduleSingle(1700, self::HOOK, [], 'cast/export');

        self::assertSame($id, $this->gateway->unscheduleAction(self::HOOK, [], 'cast/export'));
        self::assertFalse($this->gateway->hasScheduled(self::HOOK, [], 'cast/export'));
        // Nothing matched the second time: null, mirroring the real function.
        self::assertNull($this->gateway->unscheduleAction(self::HOOK, [], 'cast/export'));
    }

    public function testGatewayUnscheduleAllCancelsEveryPendingMatchInGroup(): void
    {
        $this->gateway->scheduleSingle(1700, self::HOOK, [], 'cast/export');
        $this->gateway->scheduleSingle(1700, self::HOOK, [3], 'cast/export');

        $this->gateway->unscheduleAll(self::HOOK, [], 'cast/export');

        // unscheduleAll only cancels *pending* matches for the exact
        // (hook, args, group); the args-[3] slot stays when not named.
        self::assertFalse($this->gateway->nextScheduled(self::HOOK, [], 'cast/export'));
        self::assertSame(1700, $this->gateway->nextScheduled(self::HOOK, [3], 'cast/export'));
    }

    public function testGatewayPassesThroughTheUninitializedBoundary(): void
    {
        // The shim runtime is switched to its uninitialized state: the
        // documented as_* boundary (0 / false / null / no-op) is forwarded
        // unchanged by the gateway, exactly as Action Scheduler behaves before
        // its data store is initialised.
        $GLOBALS['lumeweb_cast_actions']['available'] = false;

        self::assertSame(0, $this->gateway->scheduleSingle(1700, self::HOOK, [], 'cast/export'));
        self::assertFalse($this->gateway->nextScheduled(self::HOOK, [], 'cast/export'));
        self::assertFalse($this->gateway->hasScheduled(self::HOOK, [], 'cast/export'));
        self::assertSame(0, $this->gateway->unscheduleAction(self::HOOK, [], 'cast/export'));
        // No-op: must not throw despite nothing being cancelled.
        $this->gateway->unscheduleAll(self::HOOK, [], 'cast/export');
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
