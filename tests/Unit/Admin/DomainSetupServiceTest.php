<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\DomainDashboardView;
use LumeWeb\Cast\Admin\DomainRefusal;
use LumeWeb\Cast\Admin\DomainSetupService;
use LumeWeb\Cast\Environment\EnvIdentity;
use LumeWeb\Cast\Environment\EnvReader;
use LumeWeb\Cast\Jobs\InMemoryIdentityGateway;
use LumeWeb\Cast\Jobs\PublishIdentity;
use LumeWeb\Cast\Publish\DomainClientException;
use PHPUnit\Framework\TestCase;

/**
 * The Admin domain-setup service backs the choose-a-domain step over the
 * Publish DomainClient. These tests pin the precondition chain — a complete
 * env identity, then an existing website identity whose website id is derived
 * from IdentityGateway (never from a client-supplied parameter) — and the
 * JSON-safe mapping of list/bind/DNS/verify/delete/platform/SSL onto the typed
 * result DTOs. Every refusal is side-effect free (the client is never called)
 * and a DomainClientException maps to a typed request_failed refusal without
 * ever echoing the wrapped message (secret safety).
 */
final class DomainSetupServiceTest extends TestCase
{
    private FakeDomainClient $client;

    private InMemoryIdentityGateway $identity;

    protected function setUp(): void
    {
        $this->client = new FakeDomainClient();
        $this->identity = new InMemoryIdentityGateway();
    }

    /**
     * @param array<string, string|false> $vars
     */
    private function env(array $vars): EnvIdentity
    {
        $reader = new class ($vars) implements EnvReader {
            /** @var array<string, string|false> */
            private array $vars;

            /**
             * @param array<string, string|false> $vars
             */
            public function __construct(array $vars)
            {
                $this->vars = $vars;
            }

            public function get(string $name): string|false
            {
                return $this->vars[$name] ?? false;
            }
        };

        return new EnvIdentity($reader);
    }

    private function completeEnv(): EnvIdentity
    {
        return $this->env([
            EnvIdentity::PORTAL_API_URL => 'https://account.example.test',
            EnvIdentity::PORTAL_API_KEY => 'api-key',
        ]);
    }

    private function missingEnv(): EnvIdentity
    {
        return $this->env([EnvIdentity::PORTAL_API_URL => 'https://account.example.test']);
    }

    private function service(?EnvIdentity $env = null): DomainSetupService
    {
        return new DomainSetupService(
            env: $env ?? $this->completeEnv(),
            identity: $this->identity,
            domains: $this->client,
        );
    }

    /**
     * A ready website + IPNS identity, after publish setup completes.
     */
    private function readyIdentity(): PublishIdentity
    {
        return new PublishIdentity('web-42', 'My Blog', 'k-ipns-7', 'cast-live', true);
    }

    /* --------------------------- env identity --------------------------- */

    public function testListRefusesWhenEnvIdentityMissingWithoutTouchingTheClient(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $result = $this->service($this->missingEnv())->list();

        self::assertFalse($result->listed);
        self::assertSame(DomainRefusal::EnvIdentityMissing, $result->refusal);
        self::assertSame([], $result->domains);
        self::assertSame([], $this->client->calls);
    }

    public function testBindRefusesWhenEnvIdentityMissing(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $result = $this->service($this->missingEnv())->bind('site.example.test', 'icann');

        self::assertFalse($result->bound);
        self::assertSame(DomainRefusal::EnvIdentityMissing, $result->refusal);
        self::assertSame([], $this->client->calls);
    }

    public function testDnsRefusesWhenEnvIdentityMissing(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $result = $this->service($this->missingEnv())->dnsRequirements('99');

        self::assertFalse($result->ok);
        self::assertSame(DomainRefusal::EnvIdentityMissing, $result->refusal);
        self::assertSame([], $this->client->calls);
    }

    public function testVerifyRefusesWhenEnvIdentityMissing(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $result = $this->service($this->missingEnv())->verify('99');

        self::assertFalse($result->verified);
        self::assertSame(DomainRefusal::EnvIdentityMissing, $result->refusal);
        self::assertSame([], $this->client->calls);
    }

    public function testDeleteRefusesWhenEnvIdentityMissing(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $result = $this->service($this->missingEnv())->delete('99');

        self::assertFalse($result->deleted);
        self::assertSame(DomainRefusal::EnvIdentityMissing, $result->refusal);
        self::assertSame([], $this->client->calls);
    }

    public function testValidateRefusesWhenEnvIdentityMissing(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $result = $this->service($this->missingEnv())->validate();

        self::assertFalse($result->ok);
        self::assertSame(DomainRefusal::EnvIdentityMissing, $result->refusal);
        self::assertSame([], $this->client->calls);
    }

    public function testPlatformRefusesWhenEnvIdentityMissing(): void
    {
        $result = $this->service($this->missingEnv())->listPlatformDomains();

        self::assertFalse($result->ok);
        self::assertSame(DomainRefusal::EnvIdentityMissing, $result->refusal);
        self::assertSame([], $this->client->calls);
    }

    public function testAvailabilityRefusesWhenEnvIdentityMissing(): void
    {
        $result = $this->service($this->missingEnv())->checkPlatformAvailability('my-site');

        self::assertFalse($result->ok);
        self::assertSame(DomainRefusal::EnvIdentityMissing, $result->refusal);
        self::assertSame([], $this->client->calls);
    }

    public function testSslRefusesWhenEnvIdentityMissing(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $result = $this->service($this->missingEnv())->sslStatus('site.example.test');

        self::assertFalse($result->ok);
        self::assertSame(DomainRefusal::EnvIdentityMissing, $result->refusal);
        self::assertSame([], $this->client->calls);
    }

    /* ------------------------ website identity -------------------------- */

    public function testListRefusesWhenNoWebsiteIdentityExists(): void
    {
        $result = $this->service()->list();

        self::assertFalse($result->listed);
        self::assertSame(DomainRefusal::IdentityMissing, $result->refusal);
        self::assertSame([], $this->client->calls);
    }

    public function testBindRefusesWhenNoWebsiteIdentityExists(): void
    {
        $result = $this->service()->bind('site.example.test', 'icann');

        self::assertFalse($result->bound);
        self::assertSame(DomainRefusal::IdentityMissing, $result->refusal);
        self::assertSame([], $this->client->calls);
    }

    public function testDnsRefusesWhenNoWebsiteIdentityExists(): void
    {
        $result = $this->service()->dnsRequirements('99');

        self::assertFalse($result->ok);
        self::assertSame(DomainRefusal::IdentityMissing, $result->refusal);
    }

    public function testVerifyRefusesWhenNoWebsiteIdentityExists(): void
    {
        $result = $this->service()->verify('99');

        self::assertFalse($result->verified);
        self::assertSame(DomainRefusal::IdentityMissing, $result->refusal);
    }

    public function testDeleteRefusesWhenNoWebsiteIdentityExists(): void
    {
        $result = $this->service()->delete('99');

        self::assertFalse($result->deleted);
        self::assertSame(DomainRefusal::IdentityMissing, $result->refusal);
    }

    public function testValidateRefusesWhenNoWebsiteIdentityExists(): void
    {
        $result = $this->service()->validate();

        self::assertFalse($result->ok);
        self::assertSame(DomainRefusal::IdentityMissing, $result->refusal);
        self::assertSame([], $this->client->calls);
    }

    public function testSslRefusesWhenNoWebsiteIdentityExists(): void
    {
        $result = $this->service()->sslStatus('site.example.test');

        self::assertFalse($result->ok);
        self::assertSame(DomainRefusal::IdentityMissing, $result->refusal);
    }

    public function testPlatformAndAvailabilityDoNotRequireAWebsiteIdentity(): void
    {
        // Platform roots and label availability are pre-bind catalog reads: no
        // website is needed, only a complete env identity (the auth source).
        self::assertTrue($this->service()->listPlatformDomains()->ok);
        self::assertTrue($this->service()->checkPlatformAvailability('my-site')->ok);
    }

    public function testOperationsUseTheIdentityRegisteredWebsiteIdNotAClientSuppliedOne(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $this->service()->list();
        $this->service()->bind('site.example.test', 'icann');
        $this->service()->dnsRequirements('99');
        $this->service()->verify('99');
        $this->service()->delete('99');

        // The website id always comes from IdentityGateway::current()?->websiteId.
        self::assertSame('web-42', $this->client->calls[0][1][0] ?? null);
        self::assertSame(['web-42', 'site.example.test', 'icann'], $this->client->calls[1][1]);
        self::assertSame(['web-42', '99'], $this->client->calls[2][1]);
        self::assertSame(['web-42', '99'], $this->client->calls[3][1]);
        self::assertSame(['web-42', '99'], $this->client->calls[4][1]);
    }

    /* ---------------------------- invalid input -------------------------- */

    public function testBindRefusesAnEmptyDomain(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $result = $this->service()->bind('   ', 'icann');

        self::assertFalse($result->bound);
        self::assertSame(DomainRefusal::InvalidDomain, $result->refusal);
        self::assertSame([], $this->client->calls);
    }

    public function testBindRefusesAnUnknownNamespace(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $result = $this->service()->bind('site.example.test', 'dnssec');

        self::assertFalse($result->bound);
        self::assertSame(DomainRefusal::InvalidNamespace, $result->refusal);
        self::assertSame([], $this->client->calls);
    }

    public function testDnsRefusesAnEmptyDomainId(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $result = $this->service()->dnsRequirements(' ');

        self::assertFalse($result->ok);
        self::assertSame(DomainRefusal::InvalidDomainId, $result->refusal);
        self::assertSame([], $this->client->calls);
    }

    public function testVerifyRefusesAnEmptyDomainId(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $result = $this->service()->verify('');

        self::assertFalse($result->verified);
        self::assertSame(DomainRefusal::InvalidDomainId, $result->refusal);
        self::assertSame([], $this->client->calls);
    }

    public function testDeleteRefusesAnEmptyDomainId(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $result = $this->service()->delete('');

        self::assertFalse($result->deleted);
        self::assertSame(DomainRefusal::InvalidDomainId, $result->refusal);
        self::assertSame([], $this->client->calls);
    }

    public function testAvailabilityRefusesAnEmptyLabel(): void
    {
        $result = $this->service()->checkPlatformAvailability('');

        self::assertFalse($result->ok);
        self::assertSame(DomainRefusal::InvalidLabel, $result->refusal);
        self::assertSame([], $this->client->calls);
    }

    public function testSslRefusesAnEmptyDomain(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $result = $this->service()->sslStatus(' ');

        self::assertFalse($result->ok);
        self::assertSame(DomainRefusal::InvalidDomain, $result->refusal);
        self::assertSame([], $this->client->calls);
    }

    /* --------------------------- request failures ------------------------- */

    public function testListMapsAClientFailureToTypedRefusalWithoutLeakingMessage(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->client->throw = new DomainClientException('gateway failed with super-secret-account-key');

        $result = $this->service()->list();

        self::assertFalse($result->listed);
        self::assertSame(DomainRefusal::RequestFailed, $result->refusal);
        // The typed refusal never echoes the wrapped exception message.
        self::assertStringNotContainsString('super-secret-account-key', $result->refusal->value);
        self::assertStringNotContainsString('super-secret-account-key', (string) json_encode($result->toArray()));
    }

    public function testBindMapsAClientFailureToTypedRefusal(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->client->throw = new DomainClientException('bind failed');

        $result = $this->service()->bind('site.example.test', 'icann');

        self::assertFalse($result->bound);
        self::assertSame(DomainRefusal::RequestFailed, $result->refusal);
        self::assertNull($result->domain);
    }

    public function testVerifyMapsAClientFailureToTypedRefusal(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->client->throw = new DomainClientException('verify failed');

        $result = $this->service()->verify('99');

        self::assertFalse($result->verified);
        self::assertSame(DomainRefusal::RequestFailed, $result->refusal);
    }

    public function testDeleteMapsAClientFailureToTypedRefusal(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->client->throw = new DomainClientException('delete failed');

        $result = $this->service()->delete('99');

        self::assertFalse($result->deleted);
        self::assertSame(DomainRefusal::RequestFailed, $result->refusal);
    }

    public function testValidateMapsAClientFailureToTypedRefusalWithoutLeakingMessage(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->client->throw = new DomainClientException('validate gateway failed with super-secret-account-key');

        $result = $this->service()->validate();

        self::assertFalse($result->ok);
        self::assertSame(DomainRefusal::RequestFailed, $result->refusal);
        self::assertNull($result->validation);
        // The typed refusal never echoes the wrapped exception message.
        self::assertStringNotContainsString('super-secret-account-key', $result->refusal->value);
        self::assertStringNotContainsString('super-secret-account-key', (string) json_encode($result->toArray()));
    }

    public function testPlatformMapsAClientFailureToTypedRefusal(): void
    {
        $this->client->throw = new DomainClientException('platform failed');

        $result = $this->service()->listPlatformDomains();

        self::assertFalse($result->ok);
        self::assertSame(DomainRefusal::RequestFailed, $result->refusal);
    }

    public function testSslMapsAClientFailureToTypedRefusal(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->client->throw = new DomainClientException('ssl failed');

        $result = $this->service()->sslStatus('site.example.test');

        self::assertFalse($result->ok);
        self::assertSame(DomainRefusal::RequestFailed, $result->refusal);
    }

    /* ------------------------------- mapping ------------------------------ */

    public function testListReturnsTheSerializedDomainList(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->client->domains = [
            new \LumeWeb\Cast\Publish\Domain('99', 'site.example.test', 'icann', false, 'pending'),
            new \LumeWeb\Cast\Publish\Domain('7', 'name/', 'hns', true, 'waiting_delegation', 'gw.example.com'),
        ];

        $result = $this->service()->list();

        self::assertTrue($result->listed);
        self::assertNull($result->refusal);
        self::assertCount(2, $result->domains);

        $payload = $result->toArray();
        self::assertTrue($payload['listed']);
        self::assertSame('ok', $payload['status']);
        self::assertSame('99', $payload['domains'][0]['id']);
        self::assertSame('site.example.test', $payload['domains'][0]['domain']);
        self::assertSame('icann', $payload['domains'][0]['namespace']);
        self::assertFalse($payload['domains'][0]['dns_hosting_enabled']);
        self::assertSame('pending', $payload['domains'][0]['status']);
        self::assertNull($payload['domains'][0]['gateway_host']);
        self::assertSame('7', $payload['domains'][1]['id']);
        self::assertSame('hns', $payload['domains'][1]['namespace']);
        self::assertTrue($payload['domains'][1]['dns_hosting_enabled']);
        self::assertSame('gw.example.com', $payload['domains'][1]['gateway_host']);
        self::assertNull($payload['refusal']);
    }

    public function testBindReturnsTheBoundDomainMappedJsonSafely(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $result = $this->service()->bind('site.example.test', 'icann');

        self::assertTrue($result->bound);
        self::assertNull($result->refusal);
        self::assertSame('99', $result->domain?->id);
        self::assertSame('site.example.test', $this->client->boundDomain);
        self::assertSame('icann', $this->client->boundNamespace);

        $payload = $result->toArray();
        self::assertTrue($payload['bound']);
        self::assertSame('bound', $payload['status']);
        self::assertSame('99', $payload['domain']['id']);
        self::assertSame('site.example.test', $payload['domain']['domain']);
        self::assertSame('icann', $payload['domain']['namespace']);
        self::assertNull($payload['refusal']);
    }

    public function testDnsReturnsTheRequirementsDomain(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $result = $this->service()->dnsRequirements('9');

        self::assertTrue($result->ok);
        self::assertNull($result->refusal);
        self::assertSame('9', $result->domain?->id);
        self::assertSame('waiting_delegation', $result->domain->status);

        $payload = $result->toArray();
        self::assertTrue($payload['ok']);
        self::assertSame('ok', $payload['status']);
        self::assertSame('9', $payload['domain']['id']);
        self::assertSame('name/', $payload['domain']['domain']);
        self::assertSame('hns', $payload['domain']['namespace']);
        self::assertNull($payload['refusal']);
    }

    public function testVerifyReturnsTheVerifiedDomain(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $result = $this->service()->verify('9');

        self::assertTrue($result->verified);
        self::assertNull($result->refusal);
        self::assertSame('9', $result->domain?->id);
        self::assertSame('active', $result->domain->status);

        $payload = $result->toArray();
        self::assertTrue($payload['verified']);
        self::assertSame('verified', $payload['status']);
        self::assertSame('active', $payload['domain']['status']);
        self::assertNull($payload['refusal']);
    }

    public function testValidateReturnsTheServerComputedValidation(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->client->validation = \LumeWeb\Cast\Ipfs\WebsiteValidation::fromArray([
            'id' => 42,
            'domain' => 'site.example.test',
            'valid' => true,
            'message' => 'DNS records validated.',
            'reason' => '',
            'checks' => [
                ['name' => 'dnslink', 'ok' => true, 'message' => '', 'expected' => 'dnslink=/ipns/k-ipns-7', 'found' => ''],
            ],
        ]);

        $result = $this->service()->validate();

        self::assertTrue($result->ok);
        self::assertNull($result->refusal);
        self::assertNotNull($result->validation);
        self::assertTrue($result->validation->valid());
        // The website id is derived from the identity gateway, never from a
        // client-supplied parameter.
        self::assertSame(['validate', ['web-42']], $this->client->calls[0]);

        $payload = $result->toArray();
        self::assertTrue($payload['ok']);
        self::assertSame('ok', $payload['status']);
        self::assertTrue($payload['validation']['valid']);
        self::assertSame('site.example.test', $payload['validation']['domain']);
        self::assertSame('DNS records validated.', $payload['validation']['message']);
        self::assertCount(1, $payload['validation']['checks']);
        self::assertSame('dnslink', $payload['validation']['checks'][0]['name']);
        self::assertTrue($payload['validation']['checks'][0]['ok']);
        self::assertSame('dnslink=/ipns/k-ipns-7', $payload['validation']['checks'][0]['expected']);
        self::assertNull($payload['refusal']);
    }

    public function testDeleteDeletesTheDomainAndReturnsJsonSafeDto(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $result = $this->service()->delete('9');

        self::assertTrue($result->deleted);
        self::assertNull($result->refusal);
        // The website id is derived from the identity gateway, so delete is
        // called with the registered website id plus the binding id.
        self::assertSame(['web-42', '9'], $this->client->calls[0][1]);

        $payload = $result->toArray();
        self::assertTrue($payload['deleted']);
        self::assertSame('deleted', $payload['status']);
        self::assertSame('9', $payload['domain_id']);
        self::assertNull($payload['refusal']);
    }

    public function testPlatformListReturnsSerializedPlatformDomains(): void
    {
        $result = $this->service()->listPlatformDomains();

        self::assertTrue($result->ok);
        self::assertNull($result->refusal);

        $payload = $result->toArray();
        self::assertSame('ok', $payload['status']);
        self::assertCount(1, $payload['platform_domains']);
        $platform = $payload['platform_domains'][0];
        self::assertSame(3, $platform['id']);
        self::assertSame('pinner.xyz', $platform['domain']);
        self::assertSame('icann', $platform['namespace']);
        self::assertSame(101, $platform['zone_id']);
        self::assertTrue($platform['enabled']);
        self::assertNull($payload['refusal']);
    }

    public function testAvailabilityReturnsSerializedResults(): void
    {
        $result = $this->service()->checkPlatformAvailability('my-site');

        self::assertTrue($result->ok);
        self::assertNull($result->refusal);

        $payload = $result->toArray();
        self::assertSame('ok', $payload['status']);
        self::assertSame('my-site', $payload['availability']['label']);
        self::assertSame('pinner.xyz', $payload['availability']['results'][0]['platform_domain']);
        self::assertSame('icann', $payload['availability']['results'][0]['namespace']);
        self::assertTrue($payload['availability']['results'][0]['available']);
        self::assertNull($payload['refusal']);
    }

    public function testSslReturnsTheMappedStatus(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->client->ssl = \LumeWeb\Cast\Ipfs\SslStatusInfo::fromArray([
            'status' => 'ready',
            'issued_at' => '2026-01-02T00:00:00Z',
            'last_updated_at' => '2026-01-02T00:00:00Z',
            'error' => null,
        ]);

        $result = $this->service()->sslStatus('site.example.test');

        self::assertTrue($result->ok);
        self::assertNull($result->refusal);

        $payload = $result->toArray();
        self::assertSame('ok', $payload['status']);
        self::assertSame('ready', $payload['ssl']['status']);
        self::assertSame('2026-01-02T00:00:00Z', $payload['ssl']['issued_at']);
        self::assertSame('2026-01-02T00:00:00Z', $payload['ssl']['last_updated_at']);
        self::assertNull($payload['ssl']['error']);
        self::assertNull($payload['refusal']);
    }

    public function testSslReturnsOkWithNullWhenNoSslBlockExists(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->client->ssl = null;

        $result = $this->service()->sslStatus('site.example.test');

        self::assertTrue($result->ok);
        self::assertNull($result->refusal);
        self::assertNull($result->toArray()['ssl']);
    }

    public function testRefusalDtoIsJsonSafe(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $payload = $this->service()->bind('   ', 'icann')->toArray();

        self::assertFalse($payload['bound']);
        self::assertSame('refused', $payload['status']);
        self::assertSame('invalid_domain', $payload['refusal']);
        self::assertNull($payload['domain']);
    }

    /* ------------------------- dashboard view ---------------------------- */

    public function testDashboardBuildsTheReadyPanelWithSelectedDomainSslAndDns(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->client->domains = [
            new \LumeWeb\Cast\Publish\Domain('99', 'site.example.test', 'icann', true, 'waiting_delegation', 'gw.example.com'),
            new \LumeWeb\Cast\Publish\Domain('7', 'name/', 'hns', false, 'active', 'gw.example.com'),
        ];
        $this->client->ssl = \LumeWeb\Cast\Ipfs\SslStatusInfo::fromArray([
            'status' => 'ready',
            'issued_at' => '2026-01-02T00:00:00Z',
            'last_updated_at' => '2026-01-02T00:00:00Z',
            'error' => null,
        ]);

        $view = $this->service()->dashboard();

        self::assertSame(DomainDashboardView::STATE_READY, $view->state);
        self::assertSame(DomainDashboardView::LIST_OK, $view->listStatus);
        self::assertSame('99', $view->domains[0]['id']);
        self::assertSame('site.example.test', $view->domains[0]['domain']);
        self::assertSame(DomainDashboardView::SSL_READY, $view->sslState);
        self::assertSame(DomainDashboardView::DNS_OK, $view->dnsState);

        // list first, then the SSL + DNS reads scoped to the selected (first)
        // bound domain — the website id always derived from the identity.
        self::assertSame('list', $this->client->calls[0][0]);
        self::assertSame(['sslStatus', ['site.example.test']], $this->client->calls[1]);
        self::assertSame(['dnsRequirements', ['web-42', '99']], $this->client->calls[2]);
    }

    public function testDashboardPanelReflectsMissingWebsiteIdentity(): void
    {
        // Onboarding is complete (the publish subscriber feeds it through), but
        // no site has been published yet — the honest next step is a first
        // publish, not onboarding.
        $view = $this->service()->dashboard(onboardingComplete: true);

        self::assertSame(DomainDashboardView::STATE_SETUP, $view->state);
        self::assertSame('Publish your site to begin managing domains.', $view->stateLabel);
        self::assertSame(DomainDashboardView::LIST_REFUSED, $view->listStatus);
        self::assertFalse($view->hasWebsite);
        self::assertFalse($view->canBind);
        self::assertFalse($view->canVerify);
        self::assertSame([], $this->client->calls);
    }

    public function testDashboardPanelKeepsOnboardingCopyWhenOnboardingIncomplete(): void
    {
        // The wizard genuinely is not finished: the setup message must still
        // point at onboarding, not at a publish.
        $view = $this->service()->dashboard(onboardingComplete: false);

        self::assertSame(DomainDashboardView::STATE_SETUP, $view->state);
        self::assertSame('Complete onboarding to manage domains', $view->stateLabel);
        self::assertFalse($view->hasWebsite);
        self::assertSame([], $this->client->calls);
    }

    public function testDashboardPanelReflectsMissingEnvIdentity(): void
    {
        $view = $this->service($this->missingEnv())->dashboard();

        self::assertSame(DomainDashboardView::STATE_CONFIG, $view->state);
        self::assertSame(DomainDashboardView::LIST_REFUSED, $view->listStatus);
        self::assertFalse($view->canBind);
        self::assertSame([], $this->client->calls);
    }

    public function testDashboardPanelMarksAnEmptyListWithoutSslOrDnsReads(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $view = $this->service()->dashboard();

        self::assertSame(DomainDashboardView::STATE_READY, $view->state);
        self::assertSame(DomainDashboardView::LIST_EMPTY, $view->listStatus);
        self::assertFalse($view->canVerify);
        self::assertFalse($view->canDelete);
        self::assertCount(1, $this->client->calls);
        self::assertSame('list', $this->client->calls[0][0]);
    }

    /* ---------------------------- secret safety --------------------------- */

    public function testPayloadsNeverLeakCredentials(): void
    {
        $env = $this->env([
            EnvIdentity::PORTAL_API_URL => 'https://account.example.test',
            EnvIdentity::PORTAL_API_KEY => 'super-secret-account-key',
            EnvIdentity::WORKSPACE_AUTH_USERNAME => 'operator',
            EnvIdentity::WORKSPACE_AUTH_PASSWORD => 'workspace-pass',
            EnvIdentity::PORTAL_WORKSPACE_URL => 'https://cast.example.test',
        ]);
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->client->domains = [new \LumeWeb\Cast\Publish\Domain('99', 'site.example.test', 'icann')];

        $service = $this->service($env);
        $payloads = [
            $service->list()->toArray(),
            $service->bind('site.example.test', 'icann')->toArray(),
            $service->dnsRequirements('9')->toArray(),
            $service->verify('9')->toArray(),
            $service->delete('9')->toArray(),
            $service->listPlatformDomains()->toArray(),
            $service->checkPlatformAvailability('my-site')->toArray(),
            $service->sslStatus('site.example.test')->toArray(),
        ];

        foreach ($payloads as $payload) {
            $json = (string) json_encode($payload);
            self::assertStringNotContainsString('super-secret-account-key', $json);
            self::assertStringNotContainsString('workspace-pass', $json);
            self::assertStringNotContainsString('operator', $json);
            self::assertStringNotContainsString('https://cast.example.test', $json);
        }
    }
}
