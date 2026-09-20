<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * REST request adapter over {@see DomainSetupService}.
 *
 * The route callbacks delegate to the typed service and return the DTOs'
 * JSON-safe arrays, which WordPress' REST server serializes to JSON. Every
 * operation is a read or a single-site-bound mutation; the service enforces
 * the env/website identity preconditions and maps client failures to typed
 * refusals, so this adapter never surfaces transport details or credentials.
 */
final class DomainRestHandler
{
    public function __construct(private readonly DomainSetupService $service)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function list(): array
    {
        return $this->service->list()->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function bind(string $domain, string $namespace): array
    {
        return $this->service->bind($domain, $namespace)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function dnsRequirements(string $domainId): array
    {
        return $this->service->dnsRequirements($domainId)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $domainId): array
    {
        return $this->service->verify($domainId)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function validate(): array
    {
        return $this->service->validate()->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $domainId): array
    {
        return $this->service->delete($domainId)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function listPlatformDomains(): array
    {
        return $this->service->listPlatformDomains()->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function checkAvailability(string $label): array
    {
        return $this->service->checkPlatformAvailability($label)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function sslStatus(string $domain): array
    {
        return $this->service->sslStatus($domain)->toArray();
    }
}
