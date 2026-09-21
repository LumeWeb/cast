<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

/**
 * Immutable request payload for the ipfs-sdk BindDomain (POST
 * /api/websites/{id}/domains) call. Mirrors the swagger DomainRequest fields
 * the portal accepts: the domain and namespace, the per-binding DNS-hosting
 * flag (omitted unless explicitly chosen), the one-click platform-domain
 * fields used when claiming a managed free-subdomain label, and the generate
 * flag that lets the server derive a label. Unset optional fields are omitted
 * from the encoded JSON entirely so the server applies its defaults.
 */
final class WebsiteDomainRequest
{
    /**
     * @var array<string, true>
     */
    private const NAMESPACES = [
        'icann' => true,
        'hns' => true,
    ];

    private function __construct(
        private readonly string $domain,
        private readonly string $namespace,
        private readonly ?bool $dnsHostingEnabled = null,
        private readonly ?bool $generate = null,
        private readonly ?string $label = null,
        private readonly ?string $platformDomain = null,
        private readonly ?string $platformNamespace = null,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the namespace is not supported.
     */
    public static function create(string $domain, string $namespace): self
    {
        if (!isset(self::NAMESPACES[$namespace])) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported domain namespace "%s"; expected one of: icann, hns.',
                $namespace,
            ));
        }

        return new self($domain, $namespace);
    }

    public static function icann(string $domain): self
    {
        return self::create($domain, 'icann');
    }

    public static function hns(string $domain): self
    {
        return self::create($domain, 'hns');
    }

    public function withDnsHostingEnabled(): self
    {
        return new self(
            $this->domain,
            $this->namespace,
            true,
            $this->generate,
            $this->label,
            $this->platformDomain,
            $this->platformNamespace,
        );
    }

    public function selfManaged(): self
    {
        return new self(
            $this->domain,
            $this->namespace,
            false,
            $this->generate,
            $this->label,
            $this->platformDomain,
            $this->platformNamespace,
        );
    }

    public function generateLabel(): self
    {
        return new self(
            $this->domain,
            $this->namespace,
            $this->dnsHostingEnabled,
            true,
            $this->label,
            $this->platformDomain,
            $this->platformNamespace,
        );
    }

    public function asPlatformDomain(string $platformDomain, string $platformNamespace, string $label): self
    {
        return new self(
            $this->domain,
            $this->namespace,
            $this->dnsHostingEnabled,
            $this->generate,
            $label,
            $platformDomain,
            $platformNamespace,
        );
    }

    /**
     * @return array<string, string|bool> Body in the exact key order the portal expects.
     */
    public function toArray(): array
    {
        $body = [
            'domain' => $this->domain,
            'namespace' => $this->namespace,
        ];

        if ($this->label !== null) {
            $body['label'] = $this->label;
        }
        if ($this->platformDomain !== null) {
            $body['platform_domain'] = $this->platformDomain;
        }
        if ($this->platformNamespace !== null) {
            $body['platform_namespace'] = $this->platformNamespace;
        }
        if ($this->dnsHostingEnabled !== null) {
            $body['dns_hosting_enabled'] = $this->dnsHostingEnabled;
        }
        if ($this->generate !== null) {
            $body['generate'] = $this->generate;
        }

        return $body;
    }
}
