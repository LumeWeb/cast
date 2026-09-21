<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use ComposePress\Core\Testing\RecordingHooks;
use LumeWeb\Cast\Admin\PublishRestHandler;
use LumeWeb\Cast\Admin\PublishRestRouteRegistrar;
use LumeWeb\Cast\Admin\PublishSetupService;
use LumeWeb\Cast\Environment\EnvIdentity;
use LumeWeb\Cast\Environment\EnvReader;
use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\InMemoryRunRepository;
use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Jobs\ContentPublishScheduler;
use LumeWeb\Cast\Jobs\FixedClock;
use LumeWeb\Cast\Jobs\InMemoryIdentityGateway;
use LumeWeb\Cast\Jobs\InMemoryPublishModeStore;
use LumeWeb\Cast\Jobs\InMemoryScheduler;
use LumeWeb\Cast\Onboarding\Wizard;
use LumeWeb\Cast\Onboarding\WizardState;
use LumeWeb\Cast\Tests\Unit\Onboarding\FakeWizardStore;
use PHPUnit\Framework\TestCase;

/**
 * The REST route registrar wires the publish UX surface. These tests pin the
 * stable namespace/routes, the manage_options + wp_rest-nonce permission
 * callbacks and that the start route only queues/schedules (no long work) —
 * all through the bootstrap register_rest_route() recording shim.
 */
final class PublishRestRouteRegistrarTest extends TestCase
{
    private FakeRestAuth $auth;

    private InMemoryRunRepository $repository;

    private InMemoryScheduler $scheduler;

    private PublishRestRouteRegistrar $registrar;

    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_rest_routes'] = [];
        $this->auth = new FakeRestAuth();
        $this->repository = new InMemoryRunRepository();
        $this->scheduler = new InMemoryScheduler();

        $this->registrar = new PublishRestRouteRegistrar(
            new PublishRestHandler($this->setupService()),
            $this->auth,
        );
    }

    private function setupService(): PublishSetupService
    {
        $reader = new class implements EnvReader {
            public function get(string $name): string|false
            {
                return match ($name) {
                    EnvIdentity::PORTAL_API_URL => 'https://account.example.test',
                    EnvIdentity::PORTAL_API_KEY => 'api-key',
                    default => false,
                };
            }
        };

        $modeStore = new InMemoryPublishModeStore();
        $clock = new FixedClock(1_700_000_000);

        return new PublishSetupService(
            env: new EnvIdentity($reader),
            wizardStore: new FakeWizardStore(new Wizard(state: WizardState::Completed)),
            content: new FakePublishedContentProbe(true),
            repository: $this->repository,
            identity: new InMemoryIdentityGateway(),
            contentScheduler: new ContentPublishScheduler(
                clock: $clock,
                repository: $this->repository,
                scheduler: $this->scheduler,
                identity: new InMemoryIdentityGateway(),
                modeStore: $modeStore,
                workItems: new InMemoryWorkItemRepository(),
            ),
            modeStore: $modeStore,
            clock: $clock,
        );
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function route(string $path): array
    {
        $routes = $GLOBALS['lumeweb_cast_rest_routes'];
        self::assertIsArray($routes);

        foreach ($routes as $route) {
            self::assertIsArray($route);
            /** @var array{0: string, 1: string, 2: array<string, mixed>} $route */
            if ($route[1] === $path) {
                return $route;
            }
        }

        self::fail("REST route {$path} was not registered.");
    }

    public function testSubscribeRegistersOnlyTheRestApiInitAction(): void
    {
        $hooks = new RecordingHooks();
        $hooks->reset();

        $this->registrar->subscribe($hooks);

        self::assertSame(['rest_api_init'], $hooks->actionNames());
        self::assertSame([], $hooks->filterNames());
    }

    public function testRegisterRegistersStableStatusAndStartRoutes(): void
    {
        $this->registrar->register();

        $routes = $GLOBALS['lumeweb_cast_rest_routes'];
        self::assertIsArray($routes);
        self::assertCount(9, $routes);

        $status = $this->route(PublishRestRouteRegistrar::STATUS_ROUTE);
        self::assertSame(PublishRestRouteRegistrar::NAMESPACE, $status[0]);
        self::assertSame('GET', $status[2]['methods']);

        $start = $this->route(PublishRestRouteRegistrar::START_ROUTE);
        self::assertSame(PublishRestRouteRegistrar::NAMESPACE, $start[0]);
        self::assertSame('POST', $start[2]['methods']);

        $now = $this->route(PublishRestRouteRegistrar::NOW_ROUTE);
        self::assertSame(PublishRestRouteRegistrar::NAMESPACE, $now[0]);
        self::assertSame('POST', $now[2]['methods']);

        $mode = $this->route(PublishRestRouteRegistrar::MODE_ROUTE);
        self::assertSame(PublishRestRouteRegistrar::NAMESPACE, $mode[0]);
        self::assertSame('POST', $mode[2]['methods']);

        $cancel = $this->route(PublishRestRouteRegistrar::CANCEL_ROUTE);
        self::assertSame(PublishRestRouteRegistrar::NAMESPACE, $cancel[0]);
        self::assertSame('POST', $cancel[2]['methods']);

        $artifact = $this->route(PublishRestRouteRegistrar::ARTIFACT_ROUTE);
        self::assertSame(PublishRestRouteRegistrar::NAMESPACE, $artifact[0]);
        self::assertSame('POST', $artifact[2]['methods']);

        $websiteCreate = $this->route(PublishRestRouteRegistrar::WEBSITE_CREATE_ROUTE);
        self::assertSame(PublishRestRouteRegistrar::NAMESPACE, $websiteCreate[0]);
        self::assertSame('POST', $websiteCreate[2]['methods']);

        $websiteAvailable = $this->route(PublishRestRouteRegistrar::WEBSITE_AVAILABLE_ROUTE);
        self::assertSame(PublishRestRouteRegistrar::NAMESPACE, $websiteAvailable[0]);
        self::assertSame('GET', $websiteAvailable[2]['methods']);

        $websiteLink = $this->route(PublishRestRouteRegistrar::WEBSITE_LINK_ROUTE);
        self::assertSame(PublishRestRouteRegistrar::NAMESPACE, $websiteLink[0]);
        self::assertSame('POST', $websiteLink[2]['methods']);
    }

    public function testRoutesWireTypedCallbacksAndPermissionCallbacks(): void
    {
        $this->registrar->register();

        $status = $this->route(PublishRestRouteRegistrar::STATUS_ROUTE);
        self::assertSame([$this->registrar, 'canViewStatus'], $status[2]['permission_callback']);
        $statusCallback = $status[2]['callback'];
        self::assertIsCallable($statusCallback);

        $start = $this->route(PublishRestRouteRegistrar::START_ROUTE);
        self::assertSame([$this->registrar, 'canStart'], $start[2]['permission_callback']);
        $startCallback = $start[2]['callback'];
        self::assertIsCallable($startCallback);

        $now = $this->route(PublishRestRouteRegistrar::NOW_ROUTE);
        self::assertSame([$this->registrar, 'canPublishNow'], $now[2]['permission_callback']);
        $nowCallback = $now[2]['callback'];
        self::assertIsCallable($nowCallback);

        $mode = $this->route(PublishRestRouteRegistrar::MODE_ROUTE);
        self::assertSame([$this->registrar, 'canChangeMode'], $mode[2]['permission_callback']);
        $modeCallback = $mode[2]['callback'];
        self::assertIsCallable($modeCallback);

        $cancel = $this->route(PublishRestRouteRegistrar::CANCEL_ROUTE);
        self::assertSame([$this->registrar, 'canCancel'], $cancel[2]['permission_callback']);
        $cancelCallback = $cancel[2]['callback'];
        self::assertIsCallable($cancelCallback);

        $artifact = $this->route(PublishRestRouteRegistrar::ARTIFACT_ROUTE);
        self::assertSame([$this->registrar, 'canPublishExisting'], $artifact[2]['permission_callback']);
        $artifactCallback = $artifact[2]['callback'];
        self::assertIsCallable($artifactCallback);

        $websiteCreate = $this->route(PublishRestRouteRegistrar::WEBSITE_CREATE_ROUTE);
        self::assertSame([$this->registrar, 'canCreateWebsite'], $websiteCreate[2]['permission_callback']);
        $websiteCreateCallback = $websiteCreate[2]['callback'];
        self::assertIsCallable($websiteCreateCallback);

        $websiteAvailable = $this->route(PublishRestRouteRegistrar::WEBSITE_AVAILABLE_ROUTE);
        self::assertSame([$this->registrar, 'canListWebsites'], $websiteAvailable[2]['permission_callback']);
        $websiteAvailableCallback = $websiteAvailable[2]['callback'];
        self::assertIsCallable($websiteAvailableCallback);

        $websiteLink = $this->route(PublishRestRouteRegistrar::WEBSITE_LINK_ROUTE);
        self::assertSame([$this->registrar, 'canLinkWebsite'], $websiteLink[2]['permission_callback']);
        $websiteLinkCallback = $websiteLink[2]['callback'];
        self::assertIsCallable($websiteLinkCallback);
    }

    public function testWebsiteRoutePermissionsRequireManageOptionsAndRestNonce(): void
    {
        $this->registrar->register();

        $this->auth->allowed = false;
        self::assertFalse($this->registrar->canCreateWebsite());
        self::assertFalse($this->registrar->canListWebsites());
        self::assertFalse($this->registrar->canLinkWebsite());

        // With the capability granted the nonce is consulted and guards too.
        $this->auth->allowed = true;
        $this->auth->validNonce = false;
        self::assertFalse($this->registrar->canCreateWebsite());
        self::assertFalse($this->registrar->canListWebsites());
        self::assertFalse($this->registrar->canLinkWebsite());

        // Every nonce consulted for these routes is the wp_rest action.
        self::assertSame(['wp_rest', 'wp_rest', 'wp_rest'], $this->auth->checkedNonceActions);
    }

    public function testLinkRouteRefusesAnInvalidWebsiteIdShapeSideEffectFree(): void
    {
        $this->registrar->register();

        $link = $this->route(PublishRestRouteRegistrar::WEBSITE_LINK_ROUTE);
        $linkCallback = $link[2]['callback'];
        // Annotated on the assignment (not before the foreach below, which
        // PHPStan would read as the loop variable's type). The registrar's
        // linkWebsite() defensively reads params from any object with get_param.
        /** @var callable(object): array<string, mixed> $linkCallback */
        self::assertIsCallable($linkCallback);

        // Absent, non-numeric and non-positive website ids all refuse with the
        // fixed invalid_website_id code before the service is reached.
        foreach (['missing', 'abc', '0', '-3'] as $websiteId) {
            $payload = $linkCallback($this->restRequest(['website_id' => $websiteId]));
            self::assertFalse($payload['linked'], "website_id $websiteId must not link");
            self::assertSame('invalid_website_id', $payload['refusal']);
        }
    }

    public function testCreateRouteAcceptsOptionalHostnameAndIgnoresConfirmShape(): void
    {
        $this->registrar->register();

        $create = $this->route(PublishRestRouteRegistrar::WEBSITE_CREATE_ROUTE);
        $createCallback = $create[2]['callback'];
        // The registrar's createWebsite() reads the hostname defensively from
        // any object with get_param, so the annotated parameter is the request
        // double the test passes (an object), not a concrete WP_REST_Request.
        /** @var callable(object): array<string, mixed> $createCallback */
        self::assertIsCallable($createCallback);

        // No parked run yet: the service refuses before any wire call, but the
        // handler must accept the optional hostname + confirm shape and return
        // the typed refusal (not a PHP error for the absent param).
        $payload = $createCallback($this->restRequest(['hostname' => 'my-site.test', 'confirm' => true]));
        self::assertFalse($payload['created']);
        self::assertSame('not_awaiting_website', $payload['refusal']);

        // A non-string hostname degrades to "no hostname" (the Domain
        // stringParam convention) and still returns a typed refusal.
        $payload = $createCallback($this->restRequest(['hostname' => 42, 'confirm' => 'yes']));
        self::assertFalse($payload['created']);
        self::assertSame('not_awaiting_website', $payload['refusal']);
    }

    /**
     * A minimal WP_REST_Request double exposing get_param for the registrar's
     * defensive param readers.
     *
     * @param array<string, mixed> $params
     */
    private function restRequest(array $params): object
    {
        return new class ($params) {
            /** @var array<string, mixed> */
            private array $params;

            /**
             * @param array<string, mixed> $params
             */
            public function __construct(array $params)
            {
                $this->params = $params;
            }

            // Snake_case mirrors the WordPress WP_REST_Request API surface;
            // PSR-1's camel-caps rule intentionally exempts it.
            // phpcs:disable PSR1.Methods.CamelCapsMethodName
            public function get_param(string $name): mixed
            {
                return $this->params[$name] ?? null;
            }
            // phpcs:enable PSR1.Methods.CamelCapsMethodName
        };
    }

    public function testCancelRoutePermissionRequiresManageOptionsAndRestNonce(): void
    {
        $this->registrar->register();

        $this->auth->allowed = false;
        self::assertFalse($this->registrar->canCancel());

        // With the capability granted the nonce is consulted and guards too.
        $this->auth->allowed = true;
        $this->auth->validNonce = false;
        self::assertFalse($this->registrar->canCancel());

        // Both checks recorded: manage_options capability + wp_rest nonce.
        self::assertSame(2, count(array_values(array_filter(
            $this->auth->checkedCapabilities,
            static fn (string $capability): bool => $capability === 'manage_options',
        ))));
        self::assertSame(['wp_rest'], $this->auth->checkedNonceActions);
    }

    public function testArtifactRoutePermissionRequiresManageOptionsAndRestNonce(): void
    {
        $this->registrar->register();

        $this->auth->allowed = false;
        self::assertFalse($this->registrar->canPublishExisting());

        $this->auth->allowed = true;
        $this->auth->validNonce = false;
        self::assertFalse($this->registrar->canPublishExisting());

        self::assertSame(2, count(array_values(array_filter(
            $this->auth->checkedCapabilities,
            static fn (string $capability): bool => $capability === 'manage_options',
        ))));
        self::assertSame(['wp_rest'], $this->auth->checkedNonceActions);
    }

    public function testCancelAndArtifactCallbacksReturnJsonSafePayloadsWithoutStartingWork(): void
    {
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $this->repository->save($run);
        $this->scheduler->scheduleSingle(ContentPublishScheduler::AUTO_HOOK, 3600);

        $this->registrar->register();
        $cancel = $this->route(PublishRestRouteRegistrar::CANCEL_ROUTE);
        $cancelCallback = $cancel[2]['callback'];
        self::assertIsCallable($cancelCallback);
        /** @var callable(): array<string, mixed> $cancelCallback */
        $cancelPayload = $cancelCallback();
        self::assertTrue($cancelPayload['cancelled']);
        self::assertSame(RunStatus::Cancelled, $this->repository->find('run-1')?->status);
        self::assertSame(0, $this->scheduler->count());

        $artifact = $this->route(PublishRestRouteRegistrar::ARTIFACT_ROUTE);
        $artifactCallback = $artifact[2]['callback'];
        self::assertIsCallable($artifactCallback);
        /** @var callable(): array<string, mixed> $artifactCallback */
        $artifactPayload = $artifactCallback();
        self::assertFalse($artifactPayload['queued']);
        self::assertSame('refused', $artifactPayload['status']);
        // No intact artifact exists, so no run is seeded and nothing schedules.
        self::assertCount(1, $this->repository->list());
        self::assertSame(0, $this->scheduler->count());
    }

    public function testPermissionRequiresManageOptionsCapability(): void
    {
        $this->registrar->register();

        self::assertTrue($this->registrar->canViewStatus());
        self::assertTrue($this->registrar->canStart());

        $this->auth->allowed = false;

        self::assertFalse($this->registrar->canViewStatus());
        self::assertFalse($this->registrar->canStart());

        // Every check requires the manage_options capability and the wp_rest
        // nonce action. With the capability granted both are consulted per
        // check; once capability is revoked the && short-circuits so the nonce
        // is no longer consulted (fail fast, no unnecessary verify).
        self::assertSame(
            ['manage_options', 'manage_options', 'manage_options', 'manage_options'],
            $this->auth->checkedCapabilities,
        );
        self::assertSame(['wp_rest', 'wp_rest'], $this->auth->checkedNonceActions);
    }

    public function testPermissionRequiresValidRestNonce(): void
    {
        $this->registrar->register();

        $this->auth->validNonce = false;

        self::assertFalse($this->registrar->canViewStatus());
        self::assertFalse($this->registrar->canStart());
    }

    public function testStatusCallbackReturnsJsonSafeStatusPayload(): void
    {
        $this->registrar->register();
        $status = $this->route(PublishRestRouteRegistrar::STATUS_ROUTE);

        $callback = $status[2]['callback'];
        self::assertIsCallable($callback);
        /** @var callable(): array<string, mixed> $callback */
        $payload = $callback();

        self::assertTrue($payload['bootstrap_identity_complete']);
        self::assertTrue($payload['onboarding_complete']);
        self::assertTrue($payload['has_eligible_content']);
        self::assertSame('not_started', $payload['run_status']);
    }

    public function testStartCallbackOnlyQueuesAndSchedules(): void
    {
        $this->registrar->register();
        $start = $this->route(PublishRestRouteRegistrar::START_ROUTE);

        $callback = $start[2]['callback'];
        self::assertIsCallable($callback);
        /** @var callable(): array<string, mixed> $callback */
        $payload = $callback();

        self::assertTrue($payload['queued']);
        self::assertSame('queued', $payload['status']);

        // Exactly one dirty run and one tick: no web-request long work, no
        // duplicate stacking.
        self::assertCount(1, $this->repository->list());
        self::assertTrue($this->repository->latest()?->dirty);
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
    }
}
