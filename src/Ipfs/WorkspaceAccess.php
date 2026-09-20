<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * Workspace proxy credentials from the ipfs-sdk Workspaces.Access response
 * (WorkspaceAccessResponse). Immutable; the username/password pair is only
 * reachable through the explicit accessors and is never stringified.
 */
final class WorkspaceAccess
{
    private function __construct(
        private readonly string $username,
        private readonly string $password,
    ) {
    }

    /**
     * @throws ResponseDecodingException when a credential field is missing or not a string.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ResponseDecodingException('Workspace access response is not a JSON object.');
        }
        if (!isset($data['username']) || !is_string($data['username'])) {
            throw new ResponseDecodingException('Workspace access response is missing string field "username".');
        }
        if (!isset($data['password']) || !is_string($data['password'])) {
            throw new ResponseDecodingException('Workspace access response is missing string field "password".');
        }

        return new self($data['username'], $data['password']);
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
