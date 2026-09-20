<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Environment;

/**
 * Workspace-scoped auth credentials from the deployment environment. Exists so
 * the credential pair travels as one immutable value object and is only ever
 * exposed through the explicit username()/password() accessors — never rendered,
 * logged, or stringified by accident.
 */
final class WorkspaceAuth
{
    public function __construct(
        private readonly string $username,
        private readonly string $password,
    ) {
    }

    public function username(): string
    {
        return $this->username;
    }

    public function password(): string
    {
        return $this->password;
    }
}
