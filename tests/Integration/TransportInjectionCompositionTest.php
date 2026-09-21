<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Integration;

use LumeWeb\Cast\Jobs\ContentPublishScheduler;
use LumeWeb\Cast\Jobs\JobsHookSubscriber;
use LumeWeb\Cast\Jobs\RetentionScheduler;
use PHPUnit\Framework\TestCase;

/**
 * Transport-injection composition contract for the integration harness.
 *
 * The success-path export flow needs the publish boundary composed with a
 * deterministic, injected HttpTransport. That composition runs through an
 * {@see IntegrationHarness} shared by the integration suite — and the harness
 * must prove two invariants before any transport-driven flow can be trusted:
 *
 *  1. It boots the real plugin exactly once per process, and
 *  2. the single boot it performs registers each WordPress hook exactly once,
 *     so hook-count assertions in flow tests are meaningful.
 *
 * Both invariants are the reason transport injection is safe at all: without
 * an idempotent boot, re-running composition to inject a transport would
 * double-register every hook. This test pins the contract; the harness itself
 * (integration boot once + transport injection + hook counts) is implemented
 * in the GREEN step.
 */
final class TransportInjectionCompositionTest extends TestCase
{
    /**
     * The real plugin entry point, as WordPress invokes it.
     */
    private const PLUGIN_FILE = '/plugins/cast/cast.php';

    /**
     * The FIRST failing assertion: the harness must boot the plugin exactly
     * once and, after that single boot, register every core hook exactly once
     * (job auto-tick, follow-up, post-transition dirty marker, REST init).
     */
    public function testHarnessBootsOnceAndRegistersEachHookExactlyOnce(): void
    {
        $harness = new IntegrationHarness(self::PLUGIN_FILE);

        $harness->boot();

        self::assertSame(1, $harness->bootCount(), 'Harness must boot the plugin exactly once.');

        // rest_api_init carries two registrars under this identity-complete
        // boot: the publish routes (status/start/now/mode/cancel/artifact) and
        // the domain-setup routes (list/bind/DNS/verify/delete/platform/
        // availability/SSL).
        $expected = [
            ContentPublishScheduler::AUTO_HOOK => 1,
            ContentPublishScheduler::FOLLOW_UP_HOOK => 1,
            JobsHookSubscriber::TRANSITION_HOOK => 1,
            RetentionScheduler::RETENTION_HOOK => 1,
            'rest_api_init' => 2,
        ];
        foreach ($expected as $hook => $count) {
            self::assertSame(
                $count,
                $harness->hookCount($hook),
                sprintf('Hook %s must be registered exactly %d time(s) after a single boot.', $hook, $count),
            );
        }
    }

    /**
     * Lazy self-identification: the single boot composes the dashboard
     * Connection resolver, but MUST issue no portal request itself (no
     * GetAccount, no Workspaces.Resolve). The injected recording transport
     * backs the boot and the harness pins the count at boot time, so the proof
     * is concrete. Runtime identity is resolved only when the Connection card
     * is read — never during boot.
     */
    public function testBootIssuesNoPortalNetworkRequests(): void
    {
        $harness = new IntegrationHarness(self::PLUGIN_FILE);

        $harness->boot();

        self::assertNotNull($harness->recorder(), 'The harness must inject its recording publish transport.');
        self::assertSame(
            0,
            $harness->requestsAtBoot(),
            'A plain boot must never send a portal network request; self-identification is lazy.',
        );
    }
}
