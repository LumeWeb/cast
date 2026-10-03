<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Publish\PublishDestination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunSettingsDestinationTest extends TestCase
{
    public function testFreshRunSettingsExplicitlySnapshotNoDestinationUntilFirstPublishSetupIsConfirmed(): void
    {
        $settings = RunSettings::fresh();

        self::assertArrayHasKey('destination', $settings->toArray());
        self::assertNull($settings->toArray()['destination']);
    }

    /**
     * @return iterable<string, array{PublishDestination}>
     */
    public static function destinationProvider(): iterable
    {
        yield 'platform generated' => [PublishDestination::platformGenerated('pinned.site', 'icann')];
        yield 'platform label' => [PublishDestination::platformLabelled('my-shop', 'pinned.site', 'icann')];
        yield 'custom icann managed dns' => [PublishDestination::custom('example.com', 'icann', true)];
        yield 'custom hns self-managed dns' => [PublishDestination::custom('example.hns', 'hns', false)];
        yield 'existing website' => [PublishDestination::existing('42')];
    }

    #[DataProvider('destinationProvider')]
    public function testRunSettingsSnapshotRoundTripsTheConfirmedDestination(PublishDestination $destination): void
    {
        $settings = new RunSettings(hostname: 'origin.example.test', destination: $destination);

        $restored = RunSettings::fromArray($settings->toArray());

        self::assertEquals($destination, $restored->destination);
        // The origin hostname stays a run fact, never the destination.
        self::assertSame('origin.example.test', $restored->hostname);
    }
}
