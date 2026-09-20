<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

/**
 * WorkspaceLinker adapter over the raw ipfs-sdk workspace client.
 *
 * The {@see IpfsWorkspaceClient} already exposes the attach call; this
 * adapter exists solely to present it behind the {@see WorkspaceLinker} interface
 * without altering that client.
 */
final class IpfsWorkspaceLinker implements WorkspaceLinker
{
    public function __construct(private readonly IpfsWorkspaceClient $workspaces)
    {
    }

    public function attach(string $workspaceId, int $websiteId): Workspace
    {
        return $this->workspaces->attach($workspaceId, $websiteId);
    }
}
