<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\HttpException;

/**
 * Lazy wrapper for linking a website the account owns to this workspace
 * (POST /api/workspaces/{id}/attach).
 *
 * The guided "link an existing website" action consults this ONLY inside the
 * narrow awaiting-website check, so an ordinary poll never touches the network
 * through it. Implementations follow the typed HttpException contract of the
 * shared PSR-18 adapters.
 */
interface WorkspaceLinker
{
    /**
     * Link the website to the workspace and return the updated workspace.
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function attach(string $workspaceId, int $websiteId): Workspace;
}
