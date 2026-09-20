<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Portal;

use LumeWeb\Cast\Http\HttpException;
use LumeWeb\Cast\Http\HttpTransport;
use LumeWeb\Cast\Http\ResponseDecodingException;

/**
 * Account-service auth-key exchange client (POST /api/auth/key).
 *
 * account.pinner.xyz authenticates its identity endpoints (/api/account,
 * /api/auth/ping, ...) with a login-purpose JWT (aud=login) — the "auth key" a
 * user session holds — NOT with the workspace API key the portal injects as
 * PORTAL_API_KEY (aud=api, an API key). That API key must first be exchanged
 * through POST /api/auth/key, which validates the key and returns a fresh
 * login-purpose JWT in the JSON "token" field. Only that returned bearer is
 * accepted by the account identity endpoints (the ipfs/workspace publish
 * endpoints accept both key kinds, so they keep using PORTAL_API_KEY directly).
 *
 * The API key travels as a bearer credential that is only ever placed on the
 * wire; the typed HttpException family (transport, unexpected status,
 * decoding) is preserved for callers so the identity failure stays mappable.
 * No credential or response body ever appears in thrown error text.
 */
final class PortalAuthKeyClient
{
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * POST /api/auth/key — exchange the workspace API key (PORTAL_API_KEY) for
     * a login-purpose auth key accepted by the account identity endpoints.
     *
     * @return string the exchanged login-purpose JWT (the account bearer).
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function exchange(string $apiKey): string
    {
        $response = $this->transport->send('POST', $this->baseUrl . '/api/auth/key', [
            'Authorization' => 'Bearer ' . $apiKey,
            'Accept' => 'application/json',
        ]);
        $response->requireSuccess();

        $data = $response->json();
        $token = $data['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new ResponseDecodingException('API key exchange response did not contain a token.');
        }

        return $token;
    }
}
