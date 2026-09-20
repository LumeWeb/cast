<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Environment;

use LumeWeb\Cast\Environment\NativeEnvReader;
use PHPUnit\Framework\TestCase;

final class NativeEnvReaderTest extends TestCase
{
    private const PROBE_VAR = 'LUMEWEB_CAST_TEST_ENV_PROBE';

    protected function tearDown(): void
    {
        // Never leave the probe in the process environment, whatever happened.
        putenv(self::PROBE_VAR);
    }

    public function testReadsSetVariableAsString(): void
    {
        putenv(self::PROBE_VAR . '=some-value');

        self::assertSame('some-value', (new NativeEnvReader())->get(self::PROBE_VAR));
    }

    public function testUnsetVariableIsFalse(): void
    {
        putenv(self::PROBE_VAR);

        self::assertFalse((new NativeEnvReader())->get(self::PROBE_VAR));
    }
}
