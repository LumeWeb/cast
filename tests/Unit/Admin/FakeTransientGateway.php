<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Persistence\TransientGateway;

/**
 * In-memory TransientGateway double backing the PortalConnectionResolver
 * cross-request memo tests; the per-instance reuse across resolver instances
 * is the observable behavior under test.
 */
final class FakeTransientGateway implements TransientGateway
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key, mixed $default): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->store[$key] = $value;
    }
}
