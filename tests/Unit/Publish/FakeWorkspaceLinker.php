<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use LumeWeb\Cast\Ipfs\Workspace;
use LumeWeb\Cast\Ipfs\WorkspaceLinker;

/**
 * In-memory WorkspaceLinker fake for the publish orchestration tests. Records
 * the workspace id + website id the attach targeted (the contract under test)
 * and can be scripted to reject the attach with a typed HttpException —
 * including the 409 "already attached" conflict.
 */
final class FakeWorkspaceLinker implements WorkspaceLinker
{
    public ?string $workspaceId = null;

    public ?int $websiteId = null;

    public int $calls = 0;

    public ?\LumeWeb\Cast\Http\HttpException $attachException = null;

    public function attach(string $workspaceId, int $websiteId): Workspace
    {
        $this->calls++;
        $this->workspaceId = $workspaceId;
        $this->websiteId = $websiteId;

        if ($this->attachException !== null) {
            throw $this->attachException;
        }

        return Workspace::fromArray([
            'id' => (int) $workspaceId,
            'label' => 'main',
            'domain' => 'main.example.test',
            'status' => 'active',
        ]);
    }
}
