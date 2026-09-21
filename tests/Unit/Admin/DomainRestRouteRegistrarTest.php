<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use ComposePress\Core\Testing\RecordingHooks;
use LumeWeb\Cast\Admin\DomainRestHandler;
use LumeWeb\Cast\Admin\DomainRestRouteRegistrar;
use LumeWeb\Cast\Admin\DomainSetupService;
use LumeWeb\Cast\Environment\EnvIdentity;
use LumeWeb\Cast\Environment\EnvReader;
use LumeWeb\Cast\Jobs\InMemoryIdentityGateway;
use LumeWeb\Cast\Jobs\PublishIdentity;
use LumeWeb\Cast\Publish\Domain;
use PHPUnit\Framework\TestCase;

/**
 * The domain REST route registrar wires the admin domain-setup surface. These
 * tests pin the stable namespace/routes, the manage_options + wp_rest-nonce
 * permission callbacks and that the arg-taking callbacks pass the request
 * params through to the handler — all through the bootstrap
 * register_rest_route() recording shim.
 */
final class DomainRestRouteRegistrarTest extends TestCase
{
    private FakeRestAuth $auth;

    private FakeDomainClient $client;

    private InMemoryIdentityGateway $identity;

    private DomainRestRouteRegistrar $registrar;

    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_rest_routes'] = [];
        $this->auth = new FakeRestAuth();
        $this->client = new FakeDomainClient();
        $this->identity = new InMemoryIdentityGateway();

        $this->registrar = new DomainRestRouteRegistrar(
            new DomainRestHandler($this->service()),
            $this->auth,
        );
    }

    private function service(): DomainSetupService
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

        return new DomainSetupService(
            env: new EnvIdentity($reader),
            identity: $this->identity,
            domains: $this->client,
        );
    }

    private function readyIdentity(): PublishIdentity
    {
        return new PublishIdentity('web-42', 'My Blog', 'k-ipns-7', 'cast-live', true);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function request(array $params): object
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
            public function get_param(string $key): mixed
            {
                return $this->params[$key] ?? null;
            }
            // phpcs:enable PSR1.Methods.CamelCapsMethodName
        };
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

    public function testRegisterRegistersAllNineDomainRoutes(): void
    {
        $this->registrar->register();

        $routes = $GLOBALS['lumeweb_cast_rest_routes'];
        self::assertIsArray($routes);
        self::assertCount(9, $routes);

        $list = $this->route(DomainRestRouteRegistrar::LIST_ROUTE);
        self::assertSame(DomainRestRouteRegistrar::NAMESPACE, $list[0]);
        self::assertSame('GET', $list[2]['methods']);

        $bind = $this->route(DomainRestRouteRegistrar::BIND_ROUTE);
        self::assertSame(DomainRestRouteRegistrar::NAMESPACE, $bind[0]);
        self::assertSame('POST', $bind[2]['methods']);

        $dns = $this->route(DomainRestRouteRegistrar::DNS_ROUTE);
        self::assertSame(DomainRestRouteRegistrar::NAMESPACE, $dns[0]);
        self::assertSame('GET', $dns[2]['methods']);

        $verify = $this->route(DomainRestRouteRegistrar::VERIFY_ROUTE);
        self::assertSame(DomainRestRouteRegistrar::NAMESPACE, $verify[0]);
        self::assertSame('POST', $verify[2]['methods']);

        $validate = $this->route(DomainRestRouteRegistrar::VALIDATE_ROUTE);
        self::assertSame(DomainRestRouteRegistrar::NAMESPACE, $validate[0]);
        self::assertSame('POST', $validate[2]['methods']);

        $delete = $this->route(DomainRestRouteRegistrar::DELETE_ROUTE);
        self::assertSame(DomainRestRouteRegistrar::NAMESPACE, $delete[0]);
        self::assertSame('POST', $delete[2]['methods']);

        $platform = $this->route(DomainRestRouteRegistrar::PLATFORM_ROUTE);
        self::assertSame(DomainRestRouteRegistrar::NAMESPACE, $platform[0]);
        self::assertSame('GET', $platform[2]['methods']);

        $availability = $this->route(DomainRestRouteRegistrar::AVAILABILITY_ROUTE);
        self::assertSame(DomainRestRouteRegistrar::NAMESPACE, $availability[0]);
        self::assertSame('GET', $availability[2]['methods']);

        $ssl = $this->route(DomainRestRouteRegistrar::SSL_ROUTE);
        self::assertSame(DomainRestRouteRegistrar::NAMESPACE, $ssl[0]);
        self::assertSame('GET', $ssl[2]['methods']);
    }

    public function testRoutesWireTypedCallbacksAndPermissionCallbacks(): void
    {
        $this->registrar->register();

        $list = $this->route(DomainRestRouteRegistrar::LIST_ROUTE);
        self::assertSame([$this->registrar, 'canViewDomains'], $list[2]['permission_callback']);
        $listCallback = $list[2]['callback'];
        self::assertIsCallable($listCallback);

        $bind = $this->route(DomainRestRouteRegistrar::BIND_ROUTE);
        self::assertSame([$this->registrar, 'canBindDomain'], $bind[2]['permission_callback']);
        $bindCallback = $bind[2]['callback'];
        self::assertIsCallable($bindCallback);

        $dns = $this->route(DomainRestRouteRegistrar::DNS_ROUTE);
        self::assertSame([$this->registrar, 'canReadDns'], $dns[2]['permission_callback']);
        $dnsCallback = $dns[2]['callback'];
        self::assertIsCallable($dnsCallback);

        $verify = $this->route(DomainRestRouteRegistrar::VERIFY_ROUTE);
        self::assertSame([$this->registrar, 'canVerifyDomain'], $verify[2]['permission_callback']);
        $verifyCallback = $verify[2]['callback'];
        self::assertIsCallable($verifyCallback);

        $validate = $this->route(DomainRestRouteRegistrar::VALIDATE_ROUTE);
        self::assertSame([$this->registrar, 'canValidateDomain'], $validate[2]['permission_callback']);
        $validateCallback = $validate[2]['callback'];
        self::assertIsCallable($validateCallback);

        $delete = $this->route(DomainRestRouteRegistrar::DELETE_ROUTE);
        self::assertSame([$this->registrar, 'canDeleteDomain'], $delete[2]['permission_callback']);
        $deleteCallback = $delete[2]['callback'];
        self::assertIsCallable($deleteCallback);

        $platform = $this->route(DomainRestRouteRegistrar::PLATFORM_ROUTE);
        self::assertSame([$this->registrar, 'canReadPlatform'], $platform[2]['permission_callback']);
        $platformCallback = $platform[2]['callback'];
        self::assertIsCallable($platformCallback);

        $availability = $this->route(DomainRestRouteRegistrar::AVAILABILITY_ROUTE);
        self::assertSame([$this->registrar, 'canCheckAvailability'], $availability[2]['permission_callback']);
        $availabilityCallback = $availability[2]['callback'];
        self::assertIsCallable($availabilityCallback);

        $ssl = $this->route(DomainRestRouteRegistrar::SSL_ROUTE);
        self::assertSame([$this->registrar, 'canReadSsl'], $ssl[2]['permission_callback']);
        $sslCallback = $ssl[2]['callback'];
        self::assertIsCallable($sslCallback);
    }

    public function testDeletePermissionRequiresManageOptionsAndRestNonce(): void
    {
        $this->registrar->register();

        $this->auth->allowed = false;
        self::assertFalse($this->registrar->canDeleteDomain());

        // With the capability granted the nonce is consulted and guards too.
        $this->auth->allowed = true;
        $this->auth->validNonce = false;
        self::assertFalse($this->registrar->canDeleteDomain());

        // Both checks recorded: manage_options capability + wp_rest nonce.
        self::assertSame(2, count(array_values(array_filter(
            $this->auth->checkedCapabilities,
            static fn (string $capability): bool => $capability === 'manage_options',
        ))));
        self::assertSame(['wp_rest'], $this->auth->checkedNonceActions);
    }

    public function testSslPermissionRequiresManageOptionsAndRestNonce(): void
    {
        $this->registrar->register();

        $this->auth->allowed = false;
        self::assertFalse($this->registrar->canReadSsl());

        $this->auth->allowed = true;
        $this->auth->validNonce = false;
        self::assertFalse($this->registrar->canReadSsl());

        self::assertSame(2, count(array_values(array_filter(
            $this->auth->checkedCapabilities,
            static fn (string $capability): bool => $capability === 'manage_options',
        ))));
        self::assertSame(['wp_rest'], $this->auth->checkedNonceActions);
    }

    public function testPermissionRequiresManageOptionsCapability(): void
    {
        $this->registrar->register();

        self::assertTrue($this->registrar->canViewDomains());
        self::assertTrue($this->registrar->canReadPlatform());

        $this->auth->allowed = false;

        self::assertFalse($this->registrar->canViewDomains());
        self::assertFalse($this->registrar->canReadPlatform());

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

        self::assertFalse($this->registrar->canViewDomains());
        self::assertFalse($this->registrar->canBindDomain());
        self::assertFalse($this->registrar->canReadDns());
        self::assertFalse($this->registrar->canVerifyDomain());
        self::assertFalse($this->registrar->canValidateDomain());
        self::assertFalse($this->registrar->canDeleteDomain());
        self::assertFalse($this->registrar->canReadPlatform());
        self::assertFalse($this->registrar->canCheckAvailability());
        self::assertFalse($this->registrar->canReadSsl());
    }

    public function testListAndPlatformCallbacksReturnJsonSafePayloads(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->client->domains = [new Domain('99', 'site.example.test', 'icann', false, 'pending')];

        $this->registrar->register();

        $list = $this->route(DomainRestRouteRegistrar::LIST_ROUTE);
        $listCallback = $list[2]['callback'];
        self::assertIsCallable($listCallback);
        /** @var callable(): array<string, mixed> $listCallback */
        $listPayload = $listCallback();
        self::assertTrue($listPayload['listed']);
        self::assertSame('99', $listPayload['domains'][0]['id']);

        $platform = $this->route(DomainRestRouteRegistrar::PLATFORM_ROUTE);
        $platformCallback = $platform[2]['callback'];
        self::assertIsCallable($platformCallback);
        /** @var callable(): array<string, mixed> $platformCallback */
        $platformPayload = $platformCallback();
        self::assertTrue($platformPayload['ok']);
        self::assertSame('pinner.xyz', $platformPayload['platform_domains'][0]['domain']);
    }

    public function testArgTakingCallbacksPassRequestParamsToTheHandler(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $this->registrar->register();

        $bind = $this->route(DomainRestRouteRegistrar::BIND_ROUTE);
        $bindCallback = $bind[2]['callback'];
        self::assertIsCallable($bindCallback);
        /** @var callable(object): array<string, mixed> $bindCallback */
        $bindPayload = $bindCallback($this->request(['domain' => 'site.example.test', 'namespace' => 'icann']));
        self::assertTrue($bindPayload['bound']);
        self::assertSame('site.example.test', $bindPayload['domain']['domain']);

        $dns = $this->route(DomainRestRouteRegistrar::DNS_ROUTE);
        $dnsCallback = $dns[2]['callback'];
        self::assertIsCallable($dnsCallback);
        /** @var callable(object): array<string, mixed> $dnsCallback */
        $dnsPayload = $dnsCallback($this->request(['domain_id' => '9']));
        self::assertTrue($dnsPayload['ok']);
        self::assertSame('9', $dnsPayload['domain']['id']);

        $verify = $this->route(DomainRestRouteRegistrar::VERIFY_ROUTE);
        $verifyCallback = $verify[2]['callback'];
        self::assertIsCallable($verifyCallback);
        /** @var callable(object): array<string, mixed> $verifyCallback */
        $verifyPayload = $verifyCallback($this->request(['domain_id' => '9']));
        self::assertTrue($verifyPayload['verified']);
        self::assertSame('active', $verifyPayload['domain']['status']);

        $delete = $this->route(DomainRestRouteRegistrar::DELETE_ROUTE);
        $deleteCallback = $delete[2]['callback'];
        self::assertIsCallable($deleteCallback);
        /** @var callable(object): array<string, mixed> $deleteCallback */
        $deletePayload = $deleteCallback($this->request(['domain_id' => '9']));
        self::assertTrue($deletePayload['deleted']);
        self::assertSame('9', $deletePayload['domain_id']);

        $availability = $this->route(DomainRestRouteRegistrar::AVAILABILITY_ROUTE);
        $availabilityCallback = $availability[2]['callback'];
        self::assertIsCallable($availabilityCallback);
        /** @var callable(object): array<string, mixed> $availabilityCallback */
        $availabilityPayload = $availabilityCallback($this->request(['label' => 'my-site']));
        self::assertTrue($availabilityPayload['ok']);
        self::assertSame('my-site', $availabilityPayload['availability']['label']);

        $ssl = $this->route(DomainRestRouteRegistrar::SSL_ROUTE);
        $sslCallback = $ssl[2]['callback'];
        self::assertIsCallable($sslCallback);
        /** @var callable(object): array<string, mixed> $sslCallback */
        $sslPayload = $sslCallback($this->request(['domain' => 'site.example.test']));
        self::assertTrue($sslPayload['ok']);
        self::assertNull($sslPayload['ssl']);
    }

    public function testRouteCallbacksNeverLeakCredentials(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $this->registrar->register();

        $bind = $this->route(DomainRestRouteRegistrar::BIND_ROUTE);
        $bindCallback = $bind[2]['callback'];
        self::assertIsCallable($bindCallback);
        /** @var callable(object): array<string, mixed> $bindCallback */
        $bindPayload = $bindCallback($this->request(['domain' => 'site.example.test', 'namespace' => 'icann']));

        $json = (string) json_encode($bindPayload);
        self::assertStringNotContainsString('super-secret-account-key', $json);
        self::assertStringNotContainsString('https://cast.example.test', $json);
    }
}
