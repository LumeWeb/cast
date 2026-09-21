<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Ipfs\PlatformAvailability;
use LumeWeb\Cast\Ipfs\PlatformDomain;
use LumeWeb\Cast\Ipfs\SslStatusInfo;
use LumeWeb\Cast\Ipfs\WebsiteValidation;
use LumeWeb\Cast\Publish\Domain;
use LumeWeb\Cast\Publish\DomainClient;
use LumeWeb\Cast\Publish\DomainClientException;

/**
 * In-memory DomainClient for the Admin domain-setup service tests: records
 * every call and answers from scripted fixtures so the service's refusal and
 * mapping behaviour is verifiable without any network. Setting {@see $throw}
 * emulates the DomainClientException the real transport raises, proving the
 * service maps failures to a typed refusal without leaking the message.
 */
final class FakeDomainClient implements DomainClient
{
    /** @var list<Domain> */
    public array $domains = [];

    public ?string $boundDomain = null;

    public ?string $boundNamespace = null;

    public ?DomainClientException $throw = null;

    /** @var list<array{0: string, 1: list<string>}> */
    public array $calls = [];

    public PlatformDomain $platformDomain;

    public PlatformAvailability $availability;

    public ?SslStatusInfo $ssl = null;

    public ?WebsiteValidation $validation = null;

    public function __construct()
    {
        $this->platformDomain = PlatformDomain::fromArray([
            'id' => 3,
            'domain' => 'pinner.xyz',
            'namespace' => 'icann',
            'zone_id' => 101,
            'enabled' => true,
        ]);
        $this->availability = PlatformAvailability::fromArray([
            'label' => 'my-site',
            'results' => [
                ['platform_domain' => 'pinner.xyz', 'namespace' => 'icann', 'available' => true],
            ],
        ]);
    }

    /**
     * @return list<Domain>
     */
    public function list(string $websiteId): array
    {
        $this->record('list', [$websiteId]);
        $this->maybeThrow();

        return $this->domains;
    }

    public function bind(string $websiteId, string $domain, string $namespace): Domain
    {
        $this->record('bind', [$websiteId, $domain, $namespace]);
        $this->maybeThrow();

        $this->boundDomain = $domain;
        $this->boundNamespace = $namespace;

        return new Domain('99', $domain, $namespace, true, 'waiting_delegation', 'gw.example.com');
    }

    public function dnsRequirements(string $websiteId, string $domainId): Domain
    {
        $this->record('dnsRequirements', [$websiteId, $domainId]);
        $this->maybeThrow();

        return new Domain($domainId, 'name/', 'hns', true, 'waiting_delegation', 'gw.example.com');
    }

    public function verify(string $websiteId, string $domainId): Domain
    {
        $this->record('verify', [$websiteId, $domainId]);
        $this->maybeThrow();

        return new Domain($domainId, 'name/', 'hns', true, 'active', 'gw.example.com');
    }

    public function validate(string $websiteId): WebsiteValidation
    {
        $this->record('validate', [$websiteId]);
        $this->maybeThrow();

        return $this->validation ?? WebsiteValidation::fromArray([
            'id' => 42,
            'domain' => 'site.example.test',
            'valid' => false,
            'message' => 'DNS records not yet published.',
            'reason' => 'dns_validation_failed',
            'checks' => [
                ['name' => 'dnslink', 'ok' => false, 'message' => '', 'expected' => 'dnslink=/ipns/k-ipns-7', 'found' => ''],
            ],
        ]);
    }

    public function delete(string $websiteId, string $domainId): void
    {
        $this->record('delete', [$websiteId, $domainId]);
        $this->maybeThrow();
    }

    /**
     * @return list<PlatformDomain>
     */
    public function listPlatformDomains(): array
    {
        $this->record('listPlatformDomains', []);
        $this->maybeThrow();

        return [$this->platformDomain];
    }

    public function checkPlatformDomainAvailability(string $label): PlatformAvailability
    {
        $this->record('checkPlatformDomainAvailability', [$label]);
        $this->maybeThrow();

        return $this->availability;
    }

    public function sslStatus(string $domain): ?SslStatusInfo
    {
        $this->record('sslStatus', [$domain]);
        $this->maybeThrow();

        return $this->ssl;
    }

    /**
     * @param list<string> $args
     */
    private function record(string $operation, array $args): void
    {
        $this->calls[] = [$operation, $args];
    }

    private function maybeThrow(): void
    {
        if ($this->throw !== null) {
            $exception = $this->throw;
            $this->throw = null;

            throw $exception;
        }
    }
}
