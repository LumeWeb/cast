<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * The payload for creating a website from an upload result.
 *
 * A website cannot exist before a CID does, so targetHash is always set.
 * targetType is the internal token ('ipns' — Cast's default — or the legacy
 * 'ipfs'); the wire mapping ('website' → 'ipfs', 'ipns' → 'ipns') lives in
 * IpfsWebsitesClient::wireTargetType(), so only ipfs|ipns ever hits the wire.
 *
 * The destination intent is explicit, never derived server-side from a label
 * (the pinner-cli websites_wizard contract, mirrored by
 * {@see PublishDestination}):
 *   - platform (free) subdomain: generate + dnsHostingEnabled=true + the
 *     selected platformDomain/platformNamespace, and exactly one of
 *     generate/label; never a custom domain/namespace;
 *   - a named custom domain: domain + namespace ('icann'|'hns') + an EXPLICIT
 *     dnsHostingEnabled true|false; never generate/platform/label fields.
 * label is optional metadata only (null is omitted from the wire body).
 *
 * dnsHostingEnabled is tri-state: null (the default) omits the key from the
 * wire body entirely, while an explicit false is sent as
 * `dns_hosting_enabled: false` — omitting it would let the portal default to
 * managed hosting for a self-managed custom domain.
 */
final class CreateWebsiteRequest
{
    public function __construct(
        public readonly string $targetHash,
        public readonly string $targetType,
        public readonly ?string $label = null,
        public readonly ?string $domain = null,
        public readonly ?string $namespace = null,
        public readonly bool $generate = false,
        public readonly ?bool $dnsHostingEnabled = null,
        public readonly ?string $platformDomain = null,
        public readonly ?string $platformNamespace = null,
    ) {
    }

    /**
     * The exact website-create request a confirmed {@see PublishDestination}
     * implies — the single construction point for the platform and custom
     * wire branches, so every create path (publish orchestration, guided
     * setup actions) serializes the confirmed choice identically and can
     * never fall back to a label/hostname of its own.
     *
     * Existing destinations are attached, never created: asking for their
     * create request is a programming error.
     */
    public static function forDestination(
        PublishDestination $destination,
        string $targetHash,
        string $targetType,
    ): self {
        return match ($destination->source) {
            // Platform: managed DNS, the selected platform root/namespace, and
            // exactly one of generate/label — never a custom domain/namespace.
            PublishDestinationSource::Platform => new self(
                targetHash: $targetHash,
                targetType: $targetType,
                label: $destination->label,
                generate: $destination->generate,
                dnsHostingEnabled: true,
                platformDomain: $destination->platformDomain,
                platformNamespace: $destination->platformNamespace,
            ),
            // Custom: ONLY the explicitly entered domain, namespace, and DNS
            // hosting choice (explicit true|false) — never
            // platform/generate/label fields.
            PublishDestinationSource::Custom => new self(
                targetHash: $targetHash,
                targetType: $targetType,
                domain: $destination->domain,
                namespace: $destination->namespace,
                dnsHostingEnabled: $destination->dnsHostingEnabled,
            ),
            PublishDestinationSource::Existing => throw new \InvalidArgumentException(
                'Existing destinations are attached to the workspace, never created.',
            ),
        };
    }
}
