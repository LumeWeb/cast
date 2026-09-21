<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Environment;

use LumeWeb\Cast\Environment\SignaturePepper;
use PHPUnit\Framework\TestCase;

final class SignaturePepperTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('LUMEWEB_CAST_SIGNATURE_PEPPER');
    }

    public function testFallsBackToTheEnvironmentVariableWhenTheSaltConstantIsUndefined(): void
    {
        if (defined('AUTH_SALT')) {
            self::markTestSkipped('AUTH_SALT is already defined in this process');
        }

        putenv('LUMEWEB_CAST_SIGNATURE_PEPPER=deployment-pepper-value');

        self::assertSame('deployment-pepper-value', SignaturePepper::resolve());
    }

    public function testResolveReturnsNullWithoutAnyTrustedSecret(): void
    {
        if (defined('AUTH_SALT')) {
            self::markTestSkipped('AUTH_SALT is already defined in this process');
        }

        putenv('LUMEWEB_CAST_SIGNATURE_PEPPER');
        self::assertFalse(getenv('LUMEWEB_CAST_SIGNATURE_PEPPER'), 'the env var must be unset for this test');

        self::assertNull(
            SignaturePepper::resolve(),
            'no trusted filesystem/env secret must resolve null, not a DB-derived salt',
        );
    }

    public function testEmptyEnvironmentValuesDoNotResolve(): void
    {
        if (defined('AUTH_SALT')) {
            self::markTestSkipped('AUTH_SALT is already defined in this process');
        }

        putenv('LUMEWEB_CAST_SIGNATURE_PEPPER=   ');

        self::assertNull(SignaturePepper::resolve());
    }
}
