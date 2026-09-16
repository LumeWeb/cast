<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\RequestContext;

/**
 * Test double for RequestContext that records every gate/response call so the
 * handler's authorization policy is exercised without a web request.
 */
final class FakeRequestContext implements RequestContext
{
    public bool $allowed = true;
    public ?string $nonce = 'valid-nonce';
    public bool $nonceValid = true;
    public int $userId = 5;

    public ?string $screenId = null;

    /** @var array<string, mixed> */
    public array $params = [];

    public int $denyCount = 0;
    /** @var list<string> */
    public array $redirects = [];

    public function isCurrentUserAllowed(string $capability): bool
    {
        return $this->allowed;
    }

    public function requestNonce(): ?string
    {
        return $this->nonce;
    }

    public function requestParam(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }

    public function nonceField(string $action): string
    {
        return 'NONCE_FIELD_' . $action;
    }

    public function createNonce(string $action): string
    {
        return 'NONCE_' . $action;
    }

    public function verifyNonce(?string $nonce, string $action): bool
    {
        return $this->nonceValid && $nonce === 'valid-nonce';
    }

    public function currentUserId(): int
    {
        return $this->userId;
    }

    public function currentScreenId(): ?string
    {
        return $this->screenId;
    }

    public function deny(): void
    {
        $this->denyCount++;
    }

    public function redirectToAdminPage(string $pageSlug): void
    {
        $this->redirects[] = $pageSlug;
    }
}
