<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Publish\Contract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunSettingsTest extends TestCase
{
    public function testFreshExposesDocumentedDefaults(): void
    {
        $settings = RunSettings::fresh();

        // New runs default to the ipns mode: Cast deliberately keeps every site
        // on an IPNS publication (see the ipns default note in
        // docs/plans/guided-website-creation-during-publish.md), unlike
        // pinner-cli's ipfs default.
        self::assertSame('ipns', $settings->targetType);
        self::assertSame('', $settings->hostname);
        self::assertSame('site', $settings->artifactName);
        self::assertSame(Contract::UPLOAD_LIMIT_BYTES, $settings->uploadLimitBytes);
        self::assertSame(3, $settings->maxRetries);
        self::assertSame('', $settings->startCursor);
    }

    public function testExplicitValuesAreReadFromPromotedProperties(): void
    {
        $settings = new RunSettings(
            targetType: 'webapp',
            hostname: 'blog.example.test',
            artifactName: 'wp-export',
            uploadLimitBytes: 123456,
            maxRetries: 5,
            startCursor: 'post_id:42',
        );

        self::assertSame('webapp', $settings->targetType);
        self::assertSame('blog.example.test', $settings->hostname);
        self::assertSame('wp-export', $settings->artifactName);
        self::assertSame(123456, $settings->uploadLimitBytes);
        self::assertSame(5, $settings->maxRetries);
        self::assertSame('post_id:42', $settings->startCursor);
    }

    public function testToArrayAndFromArrayRoundTripPreservesEveryValue(): void
    {
        $settings = new RunSettings(
            targetType: 'webapp',
            hostname: 'blog.example.test',
            artifactName: 'wp-export',
            uploadLimitBytes: 123456,
            maxRetries: 5,
            startCursor: 'post_id:42',
        );

        self::assertEquals($settings, RunSettings::fromArray($settings->toArray()));
    }

    public function testToArraySerializesSnakeCaseKeys(): void
    {
        $settings = new RunSettings(hostname: 'x.test', maxRetries: 4);

        self::assertSame(
            [
                'target_type' => 'ipns',
                'hostname' => 'x.test',
                'artifact_name' => 'site',
                'upload_limit_bytes' => Contract::UPLOAD_LIMIT_BYTES,
                'max_retries' => 4,
                'start_cursor' => '',
            ],
            $settings->toArray(),
        );
    }

    public function testFromArrayBackCompatMapsLegacyWebsiteTokenToIpfs(): void
    {
        // Runs persisted before the ipns default stored target_type 'website'.
        // That token always meant IPFS targeting, so it is normalized to 'ipfs'
        // on read: an already-IPFS-published site keeps re-publishing as IPFS
        // instead of silently flipping to IPNS.
        $settings = RunSettings::fromArray(['target_type' => 'website']);

        self::assertSame('ipfs', $settings->targetType);
    }

    public function testFromArrayKeepsIpnsAndIpfsTokensUntouched(): void
    {
        self::assertSame('ipns', RunSettings::fromArray(['target_type' => 'ipns'])->targetType);
        self::assertSame('ipfs', RunSettings::fromArray(['target_type' => 'ipfs'])->targetType);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('partialDataProvider')]
    public function testFromArrayDefaultsMissingKeys(array $data, RunSettings $expected): void
    {
        self::assertEquals($expected, RunSettings::fromArray($data));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, RunSettings}>
     */
    public static function partialDataProvider(): iterable
    {
        yield 'empty' => [
            [],
            new RunSettings(),
        ];
        yield 'only hostname' => [
            ['hostname' => 'a.test'],
            new RunSettings(hostname: 'a.test'),
        ];
        yield 'unknown keys ignored' => [
            ['color' => 'blue', 'max_retries' => 9],
            new RunSettings(maxRetries: 9),
        ];
    }

    public function testFromArrayIgnoresWrongTypedValues(): void
    {
        $settings = RunSettings::fromArray([
            'hostname' => 123,
            'max_retries' => 'many',
            'upload_limit_bytes' => 'big',
            'start_cursor' => ['nope'],
        ]);

        self::assertSame('', $settings->hostname);
        self::assertSame(3, $settings->maxRetries);
        self::assertSame(Contract::UPLOAD_LIMIT_BYTES, $settings->uploadLimitBytes);
        self::assertSame('', $settings->startCursor);
    }
}
