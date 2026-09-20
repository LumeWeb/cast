<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Jobs\InMemoryIdentityGateway;
use PHPUnit\Framework\TestCase;

final class InMemoryIdentityGatewayTest extends TestCase
{
    public function testDefaultsToNoIdentity(): void
    {
        $gateway = new InMemoryIdentityGateway();

        self::assertFalse($gateway->hasIdentity());
    }

    public function testCanBeConstructedWithIdentityPresent(): void
    {
        $gateway = new InMemoryIdentityGateway(true);

        self::assertTrue($gateway->hasIdentity());
    }

    public function testIdentityCanBeFlippedAtRuntime(): void
    {
        $gateway = new InMemoryIdentityGateway();
        $gateway->setIdentity(true);
        self::assertTrue($gateway->hasIdentity());

        $gateway->setIdentity(false);
        self::assertFalse($gateway->hasIdentity());
    }
}
