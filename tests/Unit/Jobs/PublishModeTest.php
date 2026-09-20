<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Jobs\PublishMode;
use PHPUnit\Framework\TestCase;

final class PublishModeTest extends TestCase
{
    public function testManualIsTheDefaultMode(): void
    {
        self::assertSame(PublishMode::Manual, PublishMode::default());
    }

    public function testExposesManualAndOnUpdateValues(): void
    {
        self::assertSame('manual', PublishMode::Manual->value);
        self::assertSame('on_update', PublishMode::OnUpdate->value);
    }

    public function testParsesKnownStoredValuesBackToTheirCases(): void
    {
        self::assertSame(PublishMode::Manual, PublishMode::fromStored('manual'));
        self::assertSame(PublishMode::OnUpdate, PublishMode::fromStored('on_update'));
    }

    public function testLegacyScheduledStoredValueMigratesToOnUpdate(): void
    {
        // Scheduled was a reserved placeholder that auto-scheduled exactly like
        // On-update. It is no longer selectable; a stored 'scheduled' value
        // migrates to On-update so an existing site keeps auto-publishing.
        self::assertSame(PublishMode::OnUpdate, PublishMode::fromStored('scheduled'));
    }

    /**
     * @param mixed $corrupt
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('corruptStoredValues')]
    public function testUnknownOrMissingStoredValueFallsBackToManual(mixed $corrupt): void
    {
        self::assertSame(PublishMode::Manual, PublishMode::fromStored($corrupt));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function corruptStoredValues(): array
    {
        return [
            'missing option' => [null],
            'non string' => [42],
            'unknown value' => ['on_demand'],
        ];
    }

    public function testOnUpdateIsAutomaticAndManualIsNot(): void
    {
        self::assertFalse(PublishMode::Manual->isAutomatic());
        self::assertTrue(PublishMode::OnUpdate->isAutomatic());
    }
}
