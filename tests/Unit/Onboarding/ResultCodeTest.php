<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Onboarding;

use LumeWeb\Cast\Onboarding\InstallStatus;
use LumeWeb\Cast\Onboarding\ResultCode;
use PHPUnit\Framework\TestCase;

final class ResultCodeTest extends TestCase
{
    public function testResultCodesAreStructuredStrings(): void
    {
        self::assertSame('install_succeeded', ResultCode::InstallSucceeded->value);
        self::assertSame('install_failed', ResultCode::InstallFailed->value);
        self::assertSame('activate_succeeded', ResultCode::ActivateSucceeded->value);
        self::assertSame('activate_failed', ResultCode::ActivateFailed->value);
    }

    public function testInstallStatusesAreStructuredStrings(): void
    {
        self::assertSame('idle', InstallStatus::Idle->value);
        self::assertSame('installing', InstallStatus::Installing->value);
        self::assertSame('installed', InstallStatus::Installed->value);
        self::assertSame('activating', InstallStatus::Activating->value);
        self::assertSame('active', InstallStatus::Active->value);
        self::assertSame('failed', InstallStatus::Failed->value);
    }
}
