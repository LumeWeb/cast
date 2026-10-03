<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use LumeWeb\Cast\Publish\PublishDestination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublishDestinationTest extends TestCase
{
    public function testPlatformGenerationRoundTripsWithoutACustomDomainOrLabel(): void
    {
        $destination = PublishDestination::platformGenerated('pinned.site', 'icann');

        self::assertSame([
            'source' => 'platform',
            'domain' => null,
            'namespace' => null,
            'dns_hosting_enabled' => true,
            'platform_domain' => 'pinned.site',
            'platform_namespace' => 'icann',
            'generate' => true,
            'label' => null,
            'website_id' => null,
        ], $destination->toArray());
        self::assertSame($destination->toArray(), PublishDestination::fromArray($destination->toArray())?->toArray());
    }

    public function testPlatformLabelChoiceRoundTripsPreservingTheLabelFromStorage(): void
    {
        $destination = PublishDestination::platformLabelled('my-shop', 'pinned.site', 'icann');

        self::assertSame('platform', $destination->source->value);
        self::assertFalse($destination->generate);
        self::assertSame('my-shop', $destination->label);
        self::assertTrue($destination->dnsHostingEnabled);
        self::assertSame('pinned.site', $destination->platformDomain);
        self::assertSame('icann', $destination->platformNamespace);

        $decoded = PublishDestination::fromArray($destination->toArray());

        self::assertNotNull($decoded);
        self::assertSame('my-shop', $decoded->label);
        self::assertFalse($decoded->generate);
    }

    public function testPlatformLabelRequiresANonEmptyLabel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublishDestination::platformLabelled('  ');
    }

    public function testCustomIcannManagedDnsRoundTrips(): void
    {
        $destination = PublishDestination::custom('example.com', 'icann', true);

        $decoded = PublishDestination::fromArray($destination->toArray());

        self::assertNotNull($decoded);
        self::assertSame('custom', $decoded->source->value);
        self::assertSame('example.com', $decoded->domain);
        self::assertSame('icann', $decoded->namespace);
        self::assertTrue($decoded->dnsHostingEnabled);
    }

    public function testCustomHnsSelfManagedDnsRoundTrips(): void
    {
        $destination = PublishDestination::custom('example.hns', 'hns', false);

        $decoded = PublishDestination::fromArray($destination->toArray());

        self::assertNotNull($decoded);
        self::assertSame('example.hns', $decoded->domain);
        self::assertSame('hns', $decoded->namespace);
        self::assertFalse($decoded->dnsHostingEnabled);
    }

    public function testCustomDestinationKeepsOnlyTheExplicitDomainAndDnsChoice(): void
    {
        $destination = PublishDestination::custom('example.com', 'icann', false);

        self::assertSame('custom', $destination->source->value);
        self::assertSame('example.com', $destination->domain);
        self::assertSame('icann', $destination->namespace);
        self::assertFalse($destination->dnsHostingEnabled);
        self::assertNull($destination->platformDomain);
        self::assertFalse($destination->generate);
    }

    public function testCustomDestinationRejectsAnUnknownNamespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublishDestination::custom('example.com', 'cloudflare', true);
    }

    public function testExistingDestinationRoundTrips(): void
    {
        $destination = PublishDestination::existing('42');

        $decoded = PublishDestination::fromArray($destination->toArray());

        self::assertNotNull($decoded);
        self::assertSame('existing', $decoded->source->value);
        self::assertSame('42', $decoded->websiteId);
        self::assertNull($decoded->domain);
    }

    public function testExistingDestinationRejectsAnEmptyWebsiteId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublishDestination::existing(' ');
    }

    public function testPlatformAndExistingDestinationsNeverCarryAnOriginHost(): void
    {
        // The only address input the aggregate accepts is an explicit user
        // choice; there is no origin/build-host parameter or default.
        $generated = PublishDestination::platformGenerated();
        $labelled = PublishDestination::platformLabelled('shop');
        $existing = PublishDestination::existing('7');

        self::assertNull($generated->domain);
        self::assertNull($labelled->domain);
        self::assertNull($existing->domain);
        self::assertFalse($generated->generate === false && $labelled->label === null);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function corruptInputs(): iterable
    {
        yield 'non-array' => [null];
        yield 'not an array' => ['platform'];
        yield 'missing source' => [['domain' => 'example.com']];
        yield 'unknown source' => [['source' => 'build-host']];
        yield 'platform with both generate and label' => [
            ['source' => 'platform', 'generate' => true, 'label' => 'my-shop', 'dns_hosting_enabled' => true],
        ];
        yield 'platform with neither generate nor label' => [
            ['source' => 'platform', 'generate' => false, 'dns_hosting_enabled' => true],
        ];
        yield 'platform with a custom domain' => [
            ['source' => 'platform', 'generate' => true, 'domain' => 'example.com', 'dns_hosting_enabled' => true],
        ];
        yield 'platform with managed dns missing' => [
            ['source' => 'platform', 'generate' => true],
        ];
        yield 'custom with unknown namespace' => [
            ['source' => 'custom', 'domain' => 'example.com', 'namespace' => 'cloudflare', 'dns_hosting_enabled' => true],
        ];
        yield 'custom with missing dns choice' => [
            ['source' => 'custom', 'domain' => 'example.com', 'namespace' => 'icann'],
        ];
        yield 'custom with a platform label' => [
            ['source' => 'custom', 'domain' => 'example.com', 'namespace' => 'icann', 'dns_hosting_enabled' => true, 'label' => 'my-shop'],
        ];
        yield 'existing with a custom domain' => [
            ['source' => 'existing', 'website_id' => '42', 'domain' => 'example.com'],
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    #[DataProvider('corruptInputs')]
    public function testCorruptInputReadsAsNoDestination(mixed $value): void
    {
        self::assertNull(PublishDestination::fromArray($value));
    }
}
