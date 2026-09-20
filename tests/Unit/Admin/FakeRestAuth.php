<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\RestAuth;

/**
 * In-memory RestAuth so the REST route registrar's permission callbacks are
 * testable without a live WordPress request. Records every checked capability
 * and nonce action so tests can prove the registrar checks manage_options
 * and the wp_rest nonce on each and every check.
 */
final class FakeRestAuth implements RestAuth
{
    public bool $allowed = true;

    public bool $validNonce = true;

    /** @var list<string> */
    public array $checkedCapabilities = [];

    /** @var list<string> */
    public array $checkedNonceActions = [];

    public function currentUserCan(string $capability): bool
    {
        $this->checkedCapabilities[] = $capability;

        return $this->allowed;
    }

    public function hasValidRestNonce(string $action = 'wp_rest'): bool
    {
        $this->checkedNonceActions[] = $action;

        return $this->validNonce;
    }
}
