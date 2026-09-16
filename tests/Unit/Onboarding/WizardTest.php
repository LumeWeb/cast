<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Onboarding;

use LumeWeb\Cast\Onboarding\InstallStatus;
use LumeWeb\Cast\Onboarding\ResultCode;
use LumeWeb\Cast\Onboarding\Wizard;
use LumeWeb\Cast\Onboarding\WizardState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WizardTest extends TestCase
{
    public function testFreshStartsAtNotStartedWithSchemaVersion(): void
    {
        $wizard = Wizard::fresh();

        self::assertSame(WizardState::NotStarted, $wizard->state);
        self::assertSame(Wizard::SCHEMA_VERSION, $wizard->schemaVersion);
        self::assertNull($wizard->selectedBuilder);
        self::assertSame(InstallStatus::Idle, $wizard->installStatus);
        self::assertNull($wizard->resultCode);
    }

    public function testToArraySerializesEveryAggregateField(): void
    {
        $wizard = new Wizard(
            state: WizardState::Building,
            step: 'builder_selected',
            selectedBuilder: 'brizy',
            installStatus: InstallStatus::Active,
            resultCode: ResultCode::ActivateSucceeded,
            updatedAt: 12345,
        );

        self::assertSame([
            'schema_version' => Wizard::SCHEMA_VERSION,
            'state' => 'building',
            'step' => 'builder_selected',
            'selected_builder' => 'brizy',
            'install_status' => 'active',
            'result_code' => 'activate_succeeded',
            'updated_at' => 12345,
        ], $wizard->toArray());
    }

    public function testFromArrayRoundTripsASerializedAggregate(): void
    {
        $encoded = (new Wizard(
            state: WizardState::Building,
            step: 'builder_selected',
            selectedBuilder: 'brizy',
            installStatus: InstallStatus::Installed,
            resultCode: ResultCode::InstallSucceeded,
            updatedAt: 99,
        ))->toArray();

        $wizard = Wizard::fromArray($encoded);

        self::assertSame(WizardState::Building, $wizard->state);
        self::assertSame('builder_selected', $wizard->step);
        self::assertSame('brizy', $wizard->selectedBuilder);
        self::assertSame(InstallStatus::Installed, $wizard->installStatus);
        self::assertSame(ResultCode::InstallSucceeded, $wizard->resultCode);
        self::assertSame(99, $wizard->updatedAt);
    }

    #[DataProvider('missingOrInvalidProvider')]
    public function testMissingOrInvalidOptionDataResolvesToFreshNotStarted(mixed $data): void
    {
        $wizard = Wizard::fromArray($data);

        self::assertSame(WizardState::NotStarted, $wizard->state);
        self::assertSame(Wizard::SCHEMA_VERSION, $wizard->schemaVersion);
        self::assertNull($wizard->selectedBuilder);
        self::assertNull($wizard->resultCode);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function missingOrInvalidProvider(): iterable
    {
        yield 'null (missing option)' => [null];
        yield 'empty string' => [''];
        yield 'non-array object' => [new \stdClass()];
        yield 'schema version absent' => [['state' => 'building']];
        yield 'schema version mismatch' => [[
            'schema_version' => 999,
            'state' => 'building',
        ]];
    }

    public function testInvalidStateWithinMatchingSchemaNormalizesToNotStarted(): void
    {
        $wizard = Wizard::fromArray([
            'schema_version' => Wizard::SCHEMA_VERSION,
            'state' => 'bogus',
        ]);

        self::assertSame(WizardState::NotStarted, $wizard->state);
        self::assertSame(Wizard::SCHEMA_VERSION, $wizard->schemaVersion);
    }

    public function testTouchRecordsUpdatedAt(): void
    {
        $wizard = Wizard::fresh();
        $wizard->touch(424242);

        self::assertSame(424242, $wizard->updatedAt);
    }
}
