<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Portal;

use LumeWeb\Cast\Http\HttpException;
use LumeWeb\Cast\Http\HttpTransport;

/**
 * Runtime workspace-identity resolver over the shared PSR-18 bearer transport,
 * mapping the portal Workspaces.Resolve (GET /api/workspaces/resolve?resource_uuid=...)
 * call exactly.
 *
 * A runtime container resolves its own workspace identity from the
 * Coolify-injected COOLIFY_RESOURCE_UUID plus its workspace PORTAL_API_KEY
 * bearer — the SAME single bearer every publish/domain/upload client uses.
 * The portal's Workspaces.Access proxy Basic Auth credentials are never
 * involved: the only credential placed on the wire here is the API key bearer.
 * The typed HttpException family (transport, unexpected status, decoding) is
 * preserved for callers.
 */
final class WorkspaceResolveClient
{
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {
    }

    /**
     * GET /api/workspaces/resolve?resource_uuid=... — the deployment's
     * workspace identity (plus the optional attached Website publish
     * relationship).
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function resolve(string $resourceUuid): WorkspaceResolve
    {
        $query = $resourceUuid === '' ? '' : '?resource_uuid=' . rawurlencode($resourceUuid);

        $response = $this->transport->send('GET', $this->baseUrl . '/api/workspaces/resolve' . $query, [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
        ]);
        $response->requireSuccess();

        return WorkspaceResolve::fromArray($response->json());
    }
}
