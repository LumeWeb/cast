<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * A domain bound to a website as returned by the ipfs-sdk WebsitesService
 * domain endpoints (DomainResponse). Immutable and minimal: the binding
 * identity (id, domain, namespace, DNS-hosting flag, lifecycle status), the
 * portal gateway host for the binding, the delegation guidance when the portal
 * manages the zone, the per-record validation checks, and the current SSL
 * state. All nested blocks are null/empty when the response does not carry
 * them.
 */
final class WebsiteDomain
{
    /**
     * @param list<CheckInfo> $checks
     */
    private function __construct(
        private readonly int $id,
        private readonly string $domain,
        private readonly string $namespace,
        private readonly bool $dnsHostingEnabled,
        private readonly string $status,
        private readonly ?string $gatewayHost,
        private readonly ?string $ownerName,
        private readonly ?string $tlsaRdata,
        private readonly ?string $zoneName,
        private readonly ?DelegationInfo $delegation,
        private readonly array $checks,
        private readonly ?SslStatusInfo $ssl,
    ) {
    }

    /**
     * @throws ResponseDecodingException when a required field is missing or the wrong type.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ResponseDecodingException('Domain response is not a JSON object.');
        }

        $delegation = isset($data['delegation']) && is_array($data['delegation'])
            ? DelegationInfo::fromArray($data['delegation'])
            : null;

        $checks = [];
        if (isset($data['checks']) && is_array($data['checks'])) {
            foreach ($data['checks'] as $check) {
                $checks[] = CheckInfo::fromArray($check);
            }
        }

        $ssl = isset($data['ssl']) && is_array($data['ssl'])
            ? SslStatusInfo::fromArray($data['ssl'])
            : null;

        return new self(
            self::intField($data, 'id'),
            self::stringField($data, 'domain'),
            self::stringField($data, 'namespace'),
            self::boolField($data, 'dns_hosting_enabled'),
            self::stringField($data, 'status'),
            self::optionalStringField($data, 'gateway_host'),
            self::optionalStringField($data, 'owner_name'),
            self::optionalStringField($data, 'tlsa_rdata'),
            self::optionalStringField($data, 'zone_name'),
            $delegation,
            $checks,
            $ssl,
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function dnsHostingEnabled(): bool
    {
        return $this->dnsHostingEnabled;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function gatewayHost(): ?string
    {
        return $this->gatewayHost;
    }

    public function ownerName(): ?string
    {
        return $this->ownerName;
    }

    public function tlsaRdata(): ?string
    {
        return $this->tlsaRdata;
    }

    public function zoneName(): ?string
    {
        return $this->zoneName;
    }

    public function delegation(): ?DelegationInfo
    {
        return $this->delegation;
    }

    /**
     * @return list<CheckInfo>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    public function ssl(): ?SslStatusInfo
    {
        return $this->ssl;
    }

    /**
     * @param array<mixed> $data
     */
    private static function intField(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new ResponseDecodingException(sprintf('Domain response is missing integer field "%s".', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new ResponseDecodingException(sprintf('Domain response is missing string field "%s".', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function boolField(array $data, string $key): bool
    {
        if (!isset($data[$key]) || !is_bool($data[$key])) {
            throw new ResponseDecodingException(sprintf('Domain response is missing boolean field "%s".', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function optionalStringField(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        if (!is_string($data[$key])) {
            throw new ResponseDecodingException(sprintf('Domain response field "%s" is not a string.', $key));
        }

        return $data[$key];
    }
}
