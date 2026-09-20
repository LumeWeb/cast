<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Environment;

use LumeWeb\Cast\Environment\PortalEndpoints;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PortalEndpoints is the endpoint derivation/configuration abstraction: from
 * ONE canonical portal base (PORTAL_API_URL, e.g. https://pinner.xyz) it
 * derives the account/auth base (https://account.pinner.xyz) and the
 * IPFS/workspace/publish API base (https://ipfs.pinner.xyz). Derivation parses
 * the base (never brittle string replacement), preserves non-default ports and
 * any path, drops query/fragment, and rejects invalid bases.
 */
final class PortalEndpointsTest extends TestCase
{
    public function testDerivesAccountAndIpfsSubdomainsFromCanonicalBase(): void
    {
        $endpoints = PortalEndpoints::parse('https://pinner.xyz');

        self::assertNotNull($endpoints);
        self::assertSame('https://pinner.xyz', $endpoints->canonicalBaseUrl());
        self::assertSame('https://account.pinner.xyz', $endpoints->accountBaseUrl());
        self::assertSame('https://ipfs.pinner.xyz', $endpoints->ipfsBaseUrl());
    }

    public function testPreservesNonDefaultPortOnEveryDerivedEndpoint(): void
    {
        $endpoints = PortalEndpoints::parse('https://pinner.xyz:8443');

        self::assertNotNull($endpoints);
        self::assertSame('https://pinner.xyz:8443', $endpoints->canonicalBaseUrl());
        self::assertSame('https://account.pinner.xyz:8443', $endpoints->accountBaseUrl());
        self::assertSame('https://ipfs.pinner.xyz:8443', $endpoints->ipfsBaseUrl());
    }

    public function testPreservesExplicitDefaultPortAsGiven(): void
    {
        $endpoints = PortalEndpoints::parse('https://pinner.xyz:443');

        self::assertNotNull($endpoints);
        self::assertSame('https://pinner.xyz:443', $endpoints->canonicalBaseUrl());
        self::assertSame('https://account.pinner.xyz:443', $endpoints->accountBaseUrl());
        self::assertSame('https://ipfs.pinner.xyz:443', $endpoints->ipfsBaseUrl());
    }

    public function testPreservesPathOnEveryDerivedEndpointAndTrimsTrailingSlash(): void
    {
        $endpoints = PortalEndpoints::parse('https://pinner.xyz/api/v1/');

        self::assertNotNull($endpoints);
        self::assertSame('https://pinner.xyz/api/v1', $endpoints->canonicalBaseUrl());
        self::assertSame('https://account.pinner.xyz/api/v1', $endpoints->accountBaseUrl());
        self::assertSame('https://ipfs.pinner.xyz/api/v1', $endpoints->ipfsBaseUrl());
    }

    public function testDropsQueryAndFragmentFromEveryDerivedEndpoint(): void
    {
        // A canonical base URL must never carry a query/fragment; the clients
        // append /api/... paths, so both are dropped during derivation.
        $endpoints = PortalEndpoints::parse('https://pinner.xyz/api?token=x#frag');

        self::assertNotNull($endpoints);
        self::assertSame('https://pinner.xyz/api', $endpoints->canonicalBaseUrl());
        self::assertSame('https://account.pinner.xyz/api', $endpoints->accountBaseUrl());
        self::assertSame('https://ipfs.pinner.xyz/api', $endpoints->ipfsBaseUrl());
    }

    public function testHttpSchemeIsDerivedLikeHttps(): void
    {
        $endpoints = PortalEndpoints::parse('http://pinner.xyz:8080/');

        self::assertNotNull($endpoints);
        self::assertSame('http://pinner.xyz:8080', $endpoints->canonicalBaseUrl());
        self::assertSame('http://account.pinner.xyz:8080', $endpoints->accountBaseUrl());
        self::assertSame('http://ipfs.pinner.xyz:8080', $endpoints->ipfsBaseUrl());
    }

    public function testIpLiteralHostsFallBackToCanonicalBaseForBothServices(): void
    {
        // IP-literal hosts cannot take a service subdomain; both derived
        // endpoints stay on the canonical base so IP-based local stacks work.
        $ipv4 = PortalEndpoints::parse('https://127.0.0.1:8443');
        self::assertNotNull($ipv4);
        self::assertSame('https://127.0.0.1:8443', $ipv4->accountBaseUrl());
        self::assertSame('https://127.0.0.1:8443', $ipv4->ipfsBaseUrl());

        $ipv6 = PortalEndpoints::parse('https://[::1]:8443');
        self::assertNotNull($ipv6);
        self::assertSame('https://[::1]:8443', $ipv6->accountBaseUrl());
        self::assertSame('https://[::1]:8443', $ipv6->ipfsBaseUrl());
    }

    public function testSubdomainHostnamesTakeTheServiceLabelNormally(): void
    {
        // The canonical base is the registrable root; a host that already has
        // labels still gets the service label prepended on the raw host.
        $endpoints = PortalEndpoints::parse('https://portal.example.test');

        self::assertNotNull($endpoints);
        self::assertSame('https://account.portal.example.test', $endpoints->accountBaseUrl());
        self::assertSame('https://ipfs.portal.example.test', $endpoints->ipfsBaseUrl());
    }

    #[DataProvider('invalidBaseUrlCases')]
    public function testInvalidBaseUrlsParseToNull(string $value): void
    {
        self::assertNull(PortalEndpoints::parse($value), 'Expected invalid base URL to be rejected: ' . $value);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidBaseUrlCases(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ["  \t "],
            'ftp scheme' => ['ftp://pinner.xyz'],
            'no scheme' => ['pinner.xyz'],
            'relative path' => ['//pinner.xyz/path'],
            'malformed' => ['not a url'],
            'empty host' => ['https://'],
            'userinfo credentials' => ['https://user:pass@pinner.xyz'],
            'mailto scheme' => ['mailto:user@pinner.xyz'],
        ];
    }
}
