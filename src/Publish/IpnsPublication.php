<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * The outcome of publishing a CID to an IPNS key: which key, which CID, and
 * the optional mutable IPNS name that ends up pointing at the content.
 */
final class IpnsPublication
{
    public function __construct(
        public readonly string $keyName,
        public readonly string $cid,
        public readonly ?string $ipnsName = null,
    ) {
    }
}
