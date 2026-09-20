<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Http;

use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;

/**
 * Guzzle-backed HttpClientFactory with portal-safe defaults: bounded timeouts,
 * TLS verification enabled, no cookie jar, non-2xx statuses returned as normal
 * responses (never thrown with the body), and no debug/logger configured so
 * credentials never reach logs. The `handler` option lets tests inject a
 * MockHandler and run entirely offline.
 */
final class GuzzleHttpClientFactory implements HttpClientFactory
{
    public function create(array $options = []): ClientInterface
    {
        return new Client(array_replace([
            'timeout' => 30.0,
            'connect_timeout' => 10.0,
            'verify' => true,
            'cookies' => false,
            'http_errors' => false,
            // Redirects are surfaced as raw responses (never auto-followed) so
            // the upload adapter owns the 307/308 policy: hop budget, loop
            // detection and request-body preservation on re-send.
            'allow_redirects' => false,
        ], $options));
    }
}
