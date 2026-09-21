<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\OffOriginUrl;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\OriginPolicy;
use LumeWeb\Cast\Export\Url;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use PHPUnit\Framework\TestCase;

final class OriginTest extends TestCase
{
    private UrlCanonicalizer $canonicalizer;
    private OriginPolicy $policy;

    protected function setUp(): void
    {
        $this->canonicalizer = new UrlCanonicalizer();
        $this->policy = new OriginPolicy();
    }

    public function testMatchesSameSchemeHostAndPort(): void
    {
        $origin = Origin::fromUrl($this->canonicalize('https://example.com/'));

        self::assertTrue($origin->matches($this->canonicalize('https://example.com/about/')));
        self::assertTrue($origin->matches($this->canonicalize('https://example.com/wp-content/x.css')));
    }

    public function testRejectsDifferentScheme(): void
    {
        $origin = Origin::fromUrl($this->canonicalize('https://example.com/'));

        self::assertFalse($origin->matches($this->canonicalize('http://example.com/')));
    }

    public function testRejectsDifferentHost(): void
    {
        $origin = Origin::fromUrl($this->canonicalize('https://example.com/'));

        self::assertFalse($origin->matches($this->canonicalize('https://sub.example.com/')));
        self::assertFalse($origin->matches($this->canonicalize('https://example.org/')));
    }

    public function testRejectsDifferentNonDefaultPort(): void
    {
        $origin = Origin::fromUrl($this->canonicalize('https://example.com:8443/'));

        self::assertTrue($origin->matches($this->canonicalize('https://example.com:8443/x')));
        self::assertFalse($origin->matches($this->canonicalize('https://example.com/')));
    }

    public function testDefaultPortIsEquivalentToOmittedPort(): void
    {
        $origin = Origin::fromUrl($this->canonicalize('https://example.com/'));

        self::assertTrue($origin->matches(new Url('https', 'example.com', 443, '/', '')));
        self::assertTrue($origin->matches(new Url('https', 'example.com', null, '/', '')));
        self::assertFalse($origin->matches(new Url('http', 'example.com', 80, '/', '')));
    }

    public function testPolicyAllowsOriginAndThrowsOffOrigin(): void
    {
        $origin = Origin::fromUrl($this->canonicalize('https://example.com/'));

        $this->policy->assertAllowed($this->canonicalize('https://example.com/sitemap.xml'), $origin);

        $this->expectException(OffOriginUrl::class);
        $this->policy->assertAllowed($this->canonicalize('https://169.254.169.254/'), $origin);
    }

    public function testStringFormOmitsDefaultPort(): void
    {
        self::assertSame('https://example.com', (string) Origin::fromUrl($this->canonicalize('https://example.com/')));
        self::assertSame('https://example.com:8443', (string) Origin::fromUrl($this->canonicalize('https://example.com:8443/')));
    }

    public function testFromPartsPreservesSchemeHostAndExplicitPort(): void
    {
        $origin = Origin::fromParts('https', 'Blog.Example.test', 8443);

        self::assertSame('https', $origin->scheme());
        self::assertSame('Blog.Example.test', $origin->host());
        self::assertSame(8443, $origin->port());
        self::assertSame('https://Blog.Example.test:8443', (string) $origin);
    }

    public function testFromPartsKeepsOmittedDefaultPortNull(): void
    {
        $origin = Origin::fromParts('https', 'blog.example.test', null);

        self::assertNull($origin->port());
        self::assertSame('https://blog.example.test', (string) $origin);
        self::assertTrue($origin->matches(new Url('https', 'blog.example.test', 443, '/', '')));
    }

    private function canonicalize(string $raw): Url
    {
        return $this->canonicalizer->canonicalize($raw);
    }
}
