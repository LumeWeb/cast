<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Jobs\PublishIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublishIdentityTest extends TestCase
{
    public function testUsesSchemaVersionOne(): void
    {
        self::assertSame(1, PublishIdentity::SCHEMA_VERSION);
    }

    public function testExposesTypedIdentityAccessors(): void
    {
        $identity = PublishIdentity::fromOptionValue(self::validOption());

        self::assertNotNull($identity);
        self::assertSame('web-42', $identity->websiteId);
        self::assertSame('My Blog', $identity->websiteName);
        self::assertSame('k-ipns-7', $identity->ipnsKeyId);
        self::assertSame('cast-live', $identity->ipnsKeyName);
        self::assertTrue($identity->isReady());
    }

    public function testRoundTripThroughToOptionValuePreservesEverything(): void
    {
        $identity = PublishIdentity::fromOptionValue(self::validOption());
        self::assertNotNull($identity);

        $reloaded = PublishIdentity::fromOptionValue($identity->toOptionValue());

        self::assertNotNull($reloaded);
        self::assertSame($identity->websiteId, $reloaded->websiteId);
        self::assertSame($identity->websiteName, $reloaded->websiteName);
        self::assertSame($identity->ipnsKeyId, $reloaded->ipnsKeyId);
        self::assertSame($identity->ipnsKeyName, $reloaded->ipnsKeyName);
        self::assertSame($identity->isReady(), $reloaded->isReady());
    }

    public function testReadyIdentityRoundTripsAsReady(): void
    {
        self::assertTrue(PublishIdentity::fromOptionValue(self::validOption())?->isReady());
    }

    public function testNotReadyIdentityIsParsedButNotReady(): void
    {
        $option = self::validOption();
        $option['ready'] = false;

        $identity = PublishIdentity::fromOptionValue($option);

        self::assertNotNull($identity);
        self::assertFalse($identity->isReady());
    }

    public function testMissingReadyDefaultsToNotReady(): void
    {
        $option = self::validOption();
        unset($option['ready']);

        $identity = PublishIdentity::fromOptionValue($option);

        self::assertNotNull($identity);
        self::assertFalse($identity->isReady());
    }

    /**
     * @param mixed $value
     */
    #[DataProvider('invalidOptionValues')]
    public function testFromOptionValueReturnsNullOnMissingOrCorruptValues(mixed $value): void
    {
        self::assertNull(PublishIdentity::fromOptionValue($value));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidOptionValues(): array
    {
        return [
            'missing option' => [null],
            'non array' => ['not-an-array'],
            'unknown schema version' => [['schema_version' => 999] + self::validOption()],
            'website missing' => [['schema_version' => 1, 'ipns_key' => self::validOption()['ipns_key']]],
            'website not array' => [['schema_version' => 1, 'website' => 'web-42', 'ipns_key' => self::validOption()['ipns_key']]],
            'website id missing' => [['schema_version' => 1, 'website' => ['name' => 'My Blog'], 'ipns_key' => self::validOption()['ipns_key']]],
            'website id empty' => [['schema_version' => 1, 'website' => ['id' => '', 'name' => 'My Blog'], 'ipns_key' => self::validOption()['ipns_key']]],
            'website id non string' => [['schema_version' => 1, 'website' => ['id' => 42, 'name' => 'My Blog'], 'ipns_key' => self::validOption()['ipns_key']]],
            'website name missing' => [['schema_version' => 1, 'website' => ['id' => 'web-42'], 'ipns_key' => self::validOption()['ipns_key']]],
            'ipns key missing' => [['schema_version' => 1, 'website' => self::validOption()['website']]],
            'ipns key not array' => [['schema_version' => 1, 'website' => self::validOption()['website'], 'ipns_key' => 'k-7']],
            'ipns key id missing' => [['schema_version' => 1, 'website' => self::validOption()['website'], 'ipns_key' => ['name' => 'cast-live']]],
            'ipns key name empty' => [['schema_version' => 1, 'website' => self::validOption()['website'], 'ipns_key' => ['id' => 'k-7', 'name' => '']]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function validOption(): array
    {
        return [
            'schema_version' => PublishIdentity::SCHEMA_VERSION,
            'ready' => true,
            'website' => [
                'id' => 'web-42',
                'name' => 'My Blog',
            ],
            'ipns_key' => [
                'id' => 'k-ipns-7',
                'name' => 'cast-live',
            ],
        ];
    }
}
