<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\LocalHostPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalHostPolicyTest extends TestCase
{
    private LocalHostPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new LocalHostPolicy();
    }

    #[DataProvider('localHosts')]
    public function testIsLocalAcceptsLocalOrigins(string $host): void
    {
        self::assertTrue($this->policy->isLocal($host));
    }

    #[DataProvider('publicHosts')]
    public function testIsLocalRejectsPublicHosts(string $host): void
    {
        self::assertFalse($this->policy->isLocal($host));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function localHosts(): iterable
    {
        yield 'explicit localhost' => ['localhost'];
        yield 'ipv4 loopback base' => ['127.0.0.1'];
        yield 'ipv4 loopback upper bound' => ['127.255.255.255'];
        yield 'ipv4 loopback within the block' => ['127.254.1.2'];
        yield 'ipv6 loopback' => ['::1'];
        // parse_url wraps IPv6 literals in brackets; the normalizer strips
        // them before classification, so the braced form must stay local.
        yield 'bracket-wrapped ipv6 loopback' => ['[::1]'];
        // Brackets are treated the same for IPv4 literals: stripped, then the
        // literal is classified as loopback.
        yield 'bracket-wrapped ipv4 loopback' => ['[127.0.0.1]'];
        yield 'reserved test hostname' => ['site.test'];
        yield 'reserved localhost hostname' => ['site.localhost'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicHosts(): iterable
    {
        // Hostnames are not IP literals, so they can never satisfy the
        // address-based loopback classification.
        yield 'public hostname' => ['example.com'];
        yield 'hostname that begins with a loopback octet' => ['127.evil.com'];
        yield 'unrelated hostname' => ['evil.com'];
        // Out-of-range octets and extra segments produce no loopback literal.
        yield 'out-of-range octet' => ['127.0.0.999'];
        yield 'too many octets' => ['127.0.0.1.2'];
    }
}
