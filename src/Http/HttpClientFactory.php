<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Http;

use Psr\Http\Client\ClientInterface;

/**
 * Builds the PSR-18 client the SDK transport layer runs on. Concrete
 * implementations own their safe defaults and network stack; callers hold only
 * the PSR-18 boundary so the transport stays swappable.
 */
interface HttpClientFactory
{
    /**
     * @param array<string, mixed> $options Implementation-specific client options merged over safe defaults.
     *                                     Test harnesses inject a MockHandler via the Guzzle `handler` option.
     */
    public function create(array $options = []): ClientInterface;
}
