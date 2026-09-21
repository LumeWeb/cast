<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\HttpException;
use LumeWeb\Cast\Http\HttpTransport;
use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * Workspace client on the shared PSR-18 transport, mapping the ipfs-sdk
 * Workspaces.List (/api/workspaces), Workspaces.Access
 * (/api/workspaces/{id}/access) and Workspaces.Attach
 * (/api/workspaces/{id}/attach) calls exactly: list reads the {data, total}
 * envelope, access encodes the workspace id into the path and adds the
 * rotate=true query only on request, and attach POSTs the {website_id} link
 * body. The API key is a bearer credential that only ever appears on the wire;
 * the typed HttpException family is preserved.
 */
final class IpfsWorkspaceClient
{
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {
    }

    /**
     * GET /api/workspaces — workspaces owned by the authenticated account.
     *
     * @return list<Workspace>
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function list(): array
    {
        $response = $this->transport->send('GET', $this->baseUrl . '/api/workspaces', $this->headers());
        $response->requireSuccess();

        $rows = $response->json()['data'] ?? null;
        if (!is_array($rows)) {
            throw new ResponseDecodingException('Workspace list response is missing the "data" array.');
        }

        $workspaces = [];
        foreach ($rows as $row) {
            $workspaces[] = Workspace::fromArray($row);
        }

        return $workspaces;
    }

    /**
     * GET /api/workspaces/{id}/access — owner's proxy Basic Auth credentials.
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function access(string $workspaceId, bool $rotate = false): WorkspaceAccess
    {
        $uri = $this->baseUrl . '/api/workspaces/' . rawurlencode($workspaceId) . '/access';
        if ($rotate) {
            $uri .= '?rotate=true';
        }

        $response = $this->transport->send('GET', $uri, $this->headers());
        $response->requireSuccess();

        return WorkspaceAccess::fromArray($response->json());
    }

    /**
     * POST /api/workspaces/{id}/attach — link a website the account owns to
     * this workspace (the publish link), mirroring the ipfs-sdk
     * Workspaces.Attach call with its WorkspaceRequest body {website_id}. The
     * workspace id is encoded into the path the same way the sibling read
     * calls encode it; the attach response is the workspace with the link
     * applied.
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function attach(string $workspaceId, int $websiteId): Workspace
    {
        $uri = $this->baseUrl . '/api/workspaces/' . rawurlencode($workspaceId) . '/attach';

        $response = $this->transport->send(
            'POST',
            $uri,
            $this->postHeaders(),
            $this->encode(['website_id' => $websiteId]),
        );
        $response->requireSuccess();

        return Workspace::fromArray($response->json());
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Headers for requests with a JSON body (POST): same bearer/Accept as the
     * read calls plus the Content-Type that names the body format.
     *
     * @return array<string, string>
     */
    private function postHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * @param array<string, string|int> $body
     */
    private function encode(array $body): string
    {
        return json_encode($body, JSON_THROW_ON_ERROR);
    }
}
