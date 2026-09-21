<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\DomainRestHandler;
use LumeWeb\Cast\Admin\DomainSetupService;
use LumeWeb\Cast\Environment\EnvIdentity;
use LumeWeb\Cast\Environment\EnvReader;
use LumeWeb\Cast\Ipfs\SslStatusInfo;
use LumeWeb\Cast\Jobs\InMemoryIdentityGateway;
use LumeWeb\Cast\Jobs\PublishIdentity;
use LumeWeb\Cast\Publish\Domain;
use PHPUnit\Framework\TestCase;

/**
 * The domain REST handler is a thin adapter that serializes the setup
 * service's typed DTOs to JSON-safe arrays (WordPress' REST server
 * JSON-encodes plain arrays). These tests pin the exact request-surface
 * contract: every operation delegates to the typed service and the payloads
 * never echo credentials.
 */
final class DomainRestHandlerTest extends TestCase
{
    private FakeDomainClient $client;

    private InMemoryIdentityGateway $identity;

    private DomainRestHandler $handler;

    protected function setUp(): void
    {
        $this->client = new FakeDomainClient();
        $this->identity = new InMemoryIdentityGateway();

        $this->handler = new DomainRestHandler($this->service());
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

    private function service(): DomainSetupService
    {
        return new DomainSetupService(
            env: $this->env([
                EnvIdentity::PORTAL_API_URL => 'https://account.example.test',
                EnvIdentity::PORTAL_API_KEY => 'api-key',
            ]),
            identity: $this->identity,
            domains: $this->client,
        );
    }

    private function readyIdentity(): PublishIdentity
    {
        return new PublishIdentity('web-42', 'My Blog', 'k-ipns-7', 'cast-live', true);
    }

    public function testListReturnsTypedJsonSafePayload(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->client->domains = [new Domain('99', 'site.example.test', 'icann', false, 'pending')];

        $payload = $this->handler->list();

        self::assertTrue($payload['listed']);
        self::assertSame('ok', $payload['status']);
        self::assertSame('99', $payload['domains'][0]['id']);
        self::assertSame('site.example.test', $payload['domains'][0]['domain']);
        self::assertSame('icann', $payload['domains'][0]['namespace']);
        self::assertNull($payload['refusal']);
    }

    public function testListReturnsTypedRefusalPayloadWithoutIdentity(): void
    {
        $payload = $this->handler->list();

        self::assertFalse($payload['listed']);
        self::assertSame('refused', $payload['status']);
        self::assertSame('identity_missing', $payload['refusal']);
        self::assertSame([], $payload['domains']);
    }

    public function testBindReturnsTypedJsonSafePayload(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $payload = $this->handler->bind('site.example.test', 'icann');

        self::assertTrue($payload['bound']);
        self::assertSame('bound', $payload['status']);
        self::assertSame('99', $payload['domain']['id']);
        self::assertSame('site.example.test', $payload['domain']['domain']);
        self::assertNull($payload['refusal']);
    }

    public function testBindReturnsTypedRefusalPayloadForAnUnknownNamespace(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $payload = $this->handler->bind('site.example.test', 'dnssec');

        self::assertFalse($payload['bound']);
        self::assertSame('refused', $payload['status']);
        self::assertSame('invalid_namespace', $payload['refusal']);
        self::assertNull($payload['domain']);
    }

    public function testDnsReturnsTypedJsonSafePayload(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $payload = $this->handler->dnsRequirements('9');

        self::assertTrue($payload['ok']);
        self::assertSame('ok', $payload['status']);
        self::assertSame('9', $payload['domain']['id']);
        self::assertSame('waiting_delegation', $payload['domain']['status']);
        self::assertNull($payload['refusal']);
    }

    public function testVerifyReturnsTypedJsonSafePayload(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $payload = $this->handler->verify('9');

        self::assertTrue($payload['verified']);
        self::assertSame('verified', $payload['status']);
        self::assertSame('active', $payload['domain']['status']);
        self::assertNull($payload['refusal']);
    }

    public function testDeleteReturnsTypedJsonSafePayload(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $payload = $this->handler->delete('9');

        self::assertTrue($payload['deleted']);
        self::assertSame('deleted', $payload['status']);
        self::assertSame('9', $payload['domain_id']);
        self::assertNull($payload['refusal']);
    }

    public function testValidateReturnsTypedJsonSafePayload(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->client->validation = \LumeWeb\Cast\Ipfs\WebsiteValidation::fromArray([
            'id' => 42,
            'domain' => 'site.example.test',
            'valid' => false,
            'message' => 'DNS records not yet published.',
            'reason' => 'dns_validation_failed',
            'checks' => [
                ['name' => 'dnslink', 'ok' => false, 'message' => '', 'expected' => 'dnslink=/ipns/k-ipns-7', 'found' => ''],
            ],
        ]);

        $payload = $this->handler->validate();

        self::assertTrue($payload['ok']);
        self::assertSame('ok', $payload['status']);
        self::assertFalse($payload['validation']['valid']);
        self::assertSame('site.example.test', $payload['validation']['domain']);
        self::assertSame('DNS records not yet published.', $payload['validation']['message']);
        self::assertSame('dns_validation_failed', $payload['validation']['reason']);
        self::assertCount(1, $payload['validation']['checks']);
        self::assertSame('dnslink', $payload['validation']['checks'][0]['name']);
        self::assertSame('dnslink=/ipns/k-ipns-7', $payload['validation']['checks'][0]['expected']);
        self::assertNull($payload['refusal']);
    }

    public function testValidateReturnsTypedRefusalPayloadWithoutIdentity(): void
    {
        $payload = $this->handler->validate();

        self::assertFalse($payload['ok']);
        self::assertSame('refused', $payload['status']);
        self::assertSame('identity_missing', $payload['refusal']);
        self::assertNull($payload['validation']);
    }

    public function testPlatformReturnsTypedJsonSafePayload(): void
    {
        $payload = $this->handler->listPlatformDomains();

        self::assertTrue($payload['ok']);
        self::assertSame('ok', $payload['status']);
        self::assertSame(3, $payload['platform_domains'][0]['id']);
        self::assertSame('pinner.xyz', $payload['platform_domains'][0]['domain']);
        self::assertNull($payload['refusal']);
    }

    public function testAvailabilityReturnsTypedJsonSafePayload(): void
    {
        $payload = $this->handler->checkAvailability('my-site');

        self::assertTrue($payload['ok']);
        self::assertSame('ok', $payload['status']);
        self::assertSame('my-site', $payload['availability']['label']);
        self::assertSame('pinner.xyz', $payload['availability']['results'][0]['platform_domain']);
        self::assertNull($payload['refusal']);
    }

    public function testSslReturnsTypedJsonSafePayload(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->client->ssl = SslStatusInfo::fromArray([
            'status' => 'ready',
            'issued_at' => '2026-01-02T00:00:00Z',
            'last_updated_at' => '2026-01-02T00:00:00Z',
            'error' => null,
        ]);

        $payload = $this->handler->sslStatus('site.example.test');

        self::assertTrue($payload['ok']);
        self::assertSame('ok', $payload['status']);
        self::assertSame('ready', $payload['ssl']['status']);
        self::assertSame('2026-01-02T00:00:00Z', $payload['ssl']['issued_at']);
        self::assertNull($payload['refusal']);
    }

    public function testSslReturnsNullSslBlockPayload(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $payload = $this->handler->sslStatus('site.example.test');

        self::assertTrue($payload['ok']);
        self::assertSame('ok', $payload['status']);
        self::assertNull($payload['ssl']);
        self::assertNull($payload['refusal']);
    }

    public function testPayloadsNeverLeakCredentials(): void
    {
        $reader = new class implements EnvReader {
            public function get(string $name): string|false
            {
                return match ($name) {
                    EnvIdentity::PORTAL_API_URL => 'https://account.example.test',
                    EnvIdentity::PORTAL_API_KEY => 'super-secret-account-key',
                    EnvIdentity::WORKSPACE_AUTH_USERNAME => 'operator',
                    EnvIdentity::WORKSPACE_AUTH_PASSWORD => 'workspace-pass',
                    EnvIdentity::PORTAL_WORKSPACE_URL => 'https://cast.example.test',
                    default => false,
                };
            }
        };
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->client->domains = [new Domain('99', 'site.example.test', 'icann')];

        $handler = new DomainRestHandler(new DomainSetupService(
            env: new EnvIdentity($reader),
            identity: $this->identity,
            domains: $this->client,
        ));

        $payloads = [
            $handler->list(),
            $handler->bind('site.example.test', 'icann'),
            $handler->dnsRequirements('9'),
            $handler->verify('9'),
            $handler->delete('9'),
            $handler->validate(),
            $handler->listPlatformDomains(),
            $handler->checkAvailability('my-site'),
            $handler->sslStatus('site.example.test'),
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
