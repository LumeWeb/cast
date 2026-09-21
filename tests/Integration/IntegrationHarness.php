<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Integration;

use LumeWeb\Cast\CastPlugin;
use LumeWeb\Cast\Environment\EnvIdentity;
use LumeWeb\Cast\Http\HttpTransport;
use LumeWeb\Cast\Jobs\ContentPublishScheduler;
use LumeWeb\Cast\Jobs\JobsHookSubscriber;
use LumeWeb\Cast\Jobs\RetentionScheduler;
use LumeWeb\Cast\Tests\Unit\Support\RecordingTransport;

/**
 * Shared integration harness: performs the SINGLE real boot of the Cast plugin
 * per process, with a deterministic recording publish transport injected, and
 * exposes the two invariant facts the rest of the suite builds on.
 *
 * Why a harness at all:
 *  - the success-path export flow needs the publish boundary composed with an
 *    injectable, offline HttpTransport, but the real entry point (cast.php)
 *    can only call CastPlugin::boot() without a transport;
 *  - re-booting afterwards to inject a transport would double-register every
 *    WordPress hook (CastPlugin::boot() is idempotent, but WordPress additions
 *    to $wp_filter are not);
 *  - so the harness owns the single boot: it sets a test-only portal deployment
 *    identity, builds one RecordingTransport and hands it to
 *    CastPlugin::boot($pluginFile, $transport). tests/Integration/bootstrap.php
 *    triggers that boot before any test runs, and every later boot() call (from
 *    any instance, including the composition test) is a strict no-op.
 *
 * The recorded facts describe THAT single boot:
 *  - bootCount() is 1 from the moment of the first real boot onward;
 *  - hookCount($hook) is the exact number of callbacks the boot registered on
 *    $hook — the post-boot $wp_filter count minus the pre-boot baseline, so
 *    WordPress-core registrations never pollute the numbers.
 *
 * TransportInjectionCompositionTest pins both invariants; without them,
 * transport-driven flow tests could not trust their hook-count assertions.
 */
final class IntegrationHarness
{
    /**
     * The real plugin entry point, as WordPress invokes it.
     */
    public const PLUGIN_FILE = '/plugins/cast/cast.php';

    /**
     * Test-only portal deployment identity. These are deliberately NOT
     * production credentials and are only ever set for this PHP process
     * (putenv + $_ENV), never written to any store or file. They make
     * EnvIdentity::fromEnvironment()->resolve() complete so the publish
     * boundary composes the real (transport-injected) PublishStage factory
     * instead of the GuardedPublishStage.
     */
    private const PORTAL_API_URL = 'https://portal.integration.test';
    private const PORTAL_API_KEY = 'integration-test-only-api-key';

    /**
     * Hook names the composition contract pins to fixed registration counts per
     * single boot: the job auto-tick, the follow-up, the post-transition dirty
     * marker and the artifact-retention sweep register exactly once, while
     * rest_api_init carries the publish + domain-setup REST route registrars
     * (two callbacks under an identity-complete boot). The composition test
     * asserts the exact counts.
     *
     * @var list<string>
     */
    private const PINNED_HOOKS = [
        ContentPublishScheduler::AUTO_HOOK,
        ContentPublishScheduler::FOLLOW_UP_HOOK,
        JobsHookSubscriber::TRANSITION_HOOK,
        RetentionScheduler::RETENTION_HOOK,
        'rest_api_init',
    ];

    private static ?self $instance = null;

    /**
     * How many real boots the process performed (0 before the first, 1 after).
     */
    private static int $bootCount = 0;

    /**
     * Hook => number of callbacks the single real boot registered.
     *
     * @var array<string, int>
     */
    private static array $hookCounts = [];

    /**
     * The single recording transport injected into the real boot.
     */
    private static ?RecordingTransport $recorder = null;

    /**
     * Portal requests the single boot itself recorded (must be zero: lazy
     * self-identification, never a network call inside boot).
     */
    private static int $requestsAtBoot = 0;

    private readonly string $pluginFile;

    public function __construct(string $pluginFile = self::PLUGIN_FILE)
    {
        $this->pluginFile = $pluginFile;
    }

    public static function instance(string $pluginFile = self::PLUGIN_FILE): self
    {
        return self::$instance ??= new self($pluginFile);
    }

    /**
     * Boot the real plugin exactly once per process, injecting the recording
     * publish transport. The first invocation across the whole process (the one
     * wired into tests/Integration/bootstrap.php) performs the boot; every
     * later call from any instance is a no-op that keeps the recorded facts
     * stable.
     */
    public function boot(): void
    {
        if (self::$bootCount > 0) {
            return;
        }

        // Baseline BEFORE the boot so hookCount() measures only the boot's own
        // registrations, never WordPress-core callbacks already on the hook.
        $baseline = $this->pinnedHookCounts();

        $this->setTestOnlyPortalEnvironment();

        self::$recorder = RecordingTransport::withResponses([]);
        CastPlugin::boot($this->pluginFile, self::$recorder->transport());

        // Lazy self-identification invariant: a plain boot must perform NO
        // portal network call (the dashboard Connection resolver and the
        // workspace-token provider are only invoked on demand). Against an
        // empty MockHandler queue any boot-time request would blow up here;
        // the explicit count pins that none happened.
        self::$requestsAtBoot = count(self::$recorder->requests());
        if (self::$requestsAtBoot !== 0) {
            throw new \RuntimeException(
                'CastPlugin::boot() sent ' . self::$requestsAtBoot
                . ' portal network request(s); self-identification must stay lazy.',
            );
        }

        self::$hookCounts = $this->pinDeltas($baseline);
        self::$bootCount = 1;
    }

    /**
     * Portal requests the single boot itself recorded. Zero proves boot never
     * performs network self-identification (lazy Connection/workspace-token).
     */
    public function requestsAtBoot(): int
    {
        return self::$requestsAtBoot;
    }

    public function bootCount(): int
    {
        return self::$bootCount;
    }

    public function hookCount(string $hook): int
    {
        return self::$hookCounts[$hook] ?? 0;
    }

    /**
     * The recording transport injected into the single real boot (the future
     * channel for flow tests to queue responses through), or null before the first
     * boot. Its appendResponses() test-support method is intentionally deferred
     * until a flow test needs to enqueue responses.
     */
    public function recorder(): ?RecordingTransport
    {
        return self::$recorder;
    }

    /**
     * The HttpTransport injected into CastPlugin::boot, or null before the
     * first boot.
     */
    public function transport(): ?HttpTransport
    {
        return self::$recorder?->transport();
    }

    /**
     * Set the test-only portal deployment identity so the publish boundary
     * composes the real (transport-injected) PublishStage instead of the
     * GuardedPublishStage. Only this PHP process is affected; no production
     * file, option or store is touched.
     */
    private function setTestOnlyPortalEnvironment(): void
    {
        putenv(EnvIdentity::PORTAL_API_URL . '=' . self::PORTAL_API_URL);
        $_ENV[EnvIdentity::PORTAL_API_URL] = self::PORTAL_API_URL;
        putenv(EnvIdentity::PORTAL_API_KEY . '=' . self::PORTAL_API_KEY);
        $_ENV[EnvIdentity::PORTAL_API_KEY] = self::PORTAL_API_KEY;
    }

    /**
     * @return array<string, int>
     */
    private function pinnedHookCounts(): array
    {
        $counts = [];
        foreach (self::PINNED_HOOKS as $hook) {
            $counts[$hook] = $this->hookCallbackCount($hook);
        }

        return $counts;
    }

    /**
     * @param array<string, int> $baseline
     *
     * @return array<string, int>
     */
    private function pinDeltas(array $baseline): array
    {
        $deltas = [];
        foreach ($baseline as $hook => $before) {
            $deltas[$hook] = $this->hookCallbackCount($hook) - $before;
        }

        return $deltas;
    }

    /**
     * How many callbacks are currently registered on $hook (0 when the hook
     * has not been registered yet).
     */
    private function hookCallbackCount(string $hook): int
    {
        $registered = $GLOBALS['wp_filter'][$hook] ?? null;
        if (!$registered instanceof \WP_Hook) {
            return 0;
        }

        $count = 0;
        foreach ($registered->callbacks as $callbacks) {
            $count += count((array) $callbacks);
        }

        return $count;
    }
}
