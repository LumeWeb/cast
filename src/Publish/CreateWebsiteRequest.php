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
 * The destination intent is explicit, never derived server-side from a label:
 *   - empty hostname (auto-generated platform domain): generate=true +
 *     dnsHostingEnabled=true, no domain/namespace/label — Pinner mints the
 *     platform subdomain and never sees a made-up label that could leak into
 *     the minted name (the original "site.pinned.site" bug);
 *   - a named hostname (custom domain): domain + namespace ('icann'|'hns') +
 *     dnsHostingEnabled=true, no generate flag — mirroring the pinner CLI's
 *     custom-domain path.
 * label is optional metadata only (null is omitted from the wire body).
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
        public readonly bool $dnsHostingEnabled = false,
    ) {
    }
}
