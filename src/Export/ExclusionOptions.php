<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Immutable knobs for the enqueue exclusion policy. REST and feed URLs are
 * excluded by default but can be opted back in; the admin/login/XML-RPC and
 * stateful URLs (previews, customizer, nonces, add-to-cart, wc-ajax) are
 * always excluded.
 */
final class ExclusionOptions
{
    public function __construct(
        public readonly bool $excludeRests = true,
        public readonly bool $excludeFeeds = true,
    ) {
    }
}
