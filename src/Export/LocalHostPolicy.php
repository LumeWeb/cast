<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The TLS local-exception rule from the capture policy: TLS verification is disabled
 * only for obvious local origins — localhost, the loopback addresses, *.test and
 * *.localhost — and never for a public host. The WordPress 'local' environment
 * arm is reported by the injected {@see CaptureHttp} because it is a runtime
 * WordPress fact, while this class stays a pure hostname decision.
 */
final class LocalHostPolicy
{
    public function isLocal(string $host): bool
    {
        // parse_url(..., PHP_URL_HOST) returns bracket-wrapped IPv6 literals
        // ([::1]); strip them so the literal matches below.
        $host = strtolower(trim($host));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return true;
        }

        if (str_starts_with($host, '127.')) {
            return true;
        }

        return str_ends_with($host, '.test') || str_ends_with($host, '.localhost');
    }
}
