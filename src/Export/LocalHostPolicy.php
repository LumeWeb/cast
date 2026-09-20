<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

use IPLib\Factory;
use IPLib\Range\Type;

/**
 * The TLS local-exception rule from the capture policy: TLS verification is disabled
 * only for obvious local origins — localhost, the loopback addresses, *.test and
 * *.localhost — and never for a public host. The WordPress 'local' environment
 * arm is reported by the injected {@see CaptureHttp} because it is a runtime
 * WordPress fact, while this class stays a pure hostname decision.
 *
 * IP literals are classified through the ip-lib library rather than a hand-rolled
 * prefix or string check, so a hostname can never satisfy an address test. The
 * library is the authority on the RFC 5735 and RFC 4291 loopback ranges — the
 * whole 127.0.0.0/8 block and ::1 — that decide what counts as local.
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

        if ($host === 'localhost') {
            return true;
        }

        if (str_ends_with($host, '.test') || str_ends_with($host, '.localhost')) {
            return true;
        }

        // Hostnames are never IP literals, so parseAddressString() returns null
        // for them; only genuine addresses reach the loopback classification.
        $address = Factory::parseAddressString($host);

        return $address !== null && $address->getRangeType() === Type::T_LOOPBACK;
    }
}
