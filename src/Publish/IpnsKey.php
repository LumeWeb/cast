<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * An IPNS key created for the site; by name it is re-published on every later
 * publish so the mutable name follows the site's newest CID.
 */
final class IpnsKey
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $id = null,
    ) {
    }
}
