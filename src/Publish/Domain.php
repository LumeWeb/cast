<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

use LumeWeb\Cast\Ipfs\CheckInfo;
use LumeWeb\Cast\Ipfs\DelegationInfo;

/**
 * A domain bound to a website as exposed on the Publish boundary: the binding
 * identity the registry persists (the numeric ipfs-sdk id becomes the string
 * id), the bound name and namespace, whether the portal hosts the DNS zone,
 * the lifecycle status, the portal gateway host for the binding, and — when
 * the response carried them — the delegation guidance (nameservers, DNSSEC
 * state, parent/authoritative records) and the per-record validation checks.
 * This is the value the choose-a-domain step reads back after
 * bind/list/DNS/verify so it can render the records to publish and confirm
 * delegation.
 */
final class Domain
{
    /**
     * @param list<CheckInfo> $checks
     */
    public function __construct(
        public readonly string $id,
        public readonly string $domain,
        public readonly string $namespace,
        public readonly bool $dnsHostingEnabled = false,
        public readonly string $status = '',
        public readonly ?string $gatewayHost = null,
        public readonly ?DelegationInfo $delegation = null,
        public readonly array $checks = [],
    ) {
    }
}
