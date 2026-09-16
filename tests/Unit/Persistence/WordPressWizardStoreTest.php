<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Persistence;

use LumeWeb\Cast\Onboarding\InstallStatus;
use LumeWeb\Cast\Onboarding\ResultCode;
use LumeWeb\Cast\Onboarding\Wizard;
use LumeWeb\Cast\Onboarding\WizardState;
use LumeWeb\Cast\Persistence\WordPressWizardStore;
use PHPUnit\Framework\TestCase;

final class WordPressWizardStoreTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_options'] = [];
        $GLOBALS['lumeweb_cast_last_option_autoload'] = [];
    }

    public function testLoadReturnsFreshAggregateWhenOptionMissing(): void
    {
        $wizard = (new WordPressWizardStore())->load();

        self::assertSame(WizardState::NotStarted, $wizard->state);
    }

    public function testLoadDeserializesPersistedOption(): void
    {
        $GLOBALS['lumeweb_cast_options']['cast_onboarding'] = [
            'schema_version' => Wizard::SCHEMA_VERSION,
            'state' => 'building',
            'selected_builder' => 'brizy',
            'install_status' => 'active',
            'result_code' => 'activate_succeeded',
            'updated_at' => 7,
        ];

        $wizard = (new WordPressWizardStore())->load();

        self::assertSame(WizardState::Building, $wizard->state);
        self::assertSame('brizy', $wizard->selectedBuilder);
        self::assertSame(InstallStatus::Active, $wizard->installStatus);
        self::assertSame(ResultCode::ActivateSucceeded, $wizard->resultCode);
        self::assertSame(7, $wizard->updatedAt);
    }

    public function testSavePersistsSerializedAggregateUnderGlobalOptionWithAutoloadDisabled(): void
    {
        $store = new WordPressWizardStore();
        $store->save(new Wizard(
            state: WizardState::Completed,
            selectedBuilder: 'generateblocks',
            updatedAt: 12,
        ));

        self::assertSame('completed', $GLOBALS['lumeweb_cast_options']['cast_onboarding']['state']);
        self::assertSame('generateblocks', $GLOBALS['lumeweb_cast_options']['cast_onboarding']['selected_builder']);
        // The onboarding option must never be autoloaded on every page load.
        self::assertFalse($GLOBALS['lumeweb_cast_last_option_autoload']['cast_onboarding']);
    }

    public function testSaveDoesNotWritePerUserMeta(): void
    {
        (new WordPressWizardStore())->save(Wizard::fresh());

        self::assertSame([], $GLOBALS['lumeweb_cast_user_meta'] ?? []);
    }
}
