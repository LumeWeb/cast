<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Ipfs\Workspace;
use LumeWeb\Cast\Ipfs\WorkspaceLinker;

/**
 * In-memory WorkspaceLinker fake so the guided link action is testable without
 * a transport. Records the workspace id + website id it was asked to attach so
 * a test can pin the exact target, and can be programmed to reject the attach
 * (the "already linked" conflict the list payload cannot express).
 */
final class FakeWorkspaceLinker implements WorkspaceLinker
{
    public ?string $workspaceId = null;

    public ?int $websiteId = null;

    public ?\LumeWeb\Cast\Http\HttpException $attachException = null;

    /**
     * Optional hook run at the start of attach(), so a test can assert
     * ordering across the two collaborators (e.g. that the identity
     * write-through has NOT fired yet when the auto-attach runs).
     *
     * @var \Closure(string, int): void|null
     */
    public ?\Closure $onAttach = null;

    public function attach(string $workspaceId, int $websiteId): Workspace
    {
        $this->workspaceId = $workspaceId;
        $this->websiteId = $websiteId;

        if ($this->onAttach !== null) {
            ($this->onAttach)($workspaceId, $websiteId);
        }

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
