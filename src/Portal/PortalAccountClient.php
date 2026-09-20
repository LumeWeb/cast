<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Portal;

use LumeWeb\Cast\Http\HttpException;
use LumeWeb\Cast\Http\HttpTransport;

/**
 * Account identity client on the shared PSR-18 transport, mapping the
 * portal-sdk GetAccount (/api/account) response. The account service
 * (account.pinner.xyz) only accepts a login-purpose auth key (aud=login) on
 * /api/account, so the bearer passed here is the auth key returned by the
 * POST /api/auth/key exchange of the workspace API key (PORTAL_API_KEY) — see
 * {@see PortalAuthKeyClient} and {@see PortalFacade}. The auth key travels as a
 * bearer credential that is only ever placed on the wire; the typed
 * HttpException family (transport, unexpected status, decoding) is preserved
 * for callers so per-endpoint mapping stays possible without re-wrapping.
 */
final class PortalAccountClient
{
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly string $baseUrl,
        private readonly string $authKey,
    ) {
    }

    /**
     * GET /api/account — the authenticated account's identity.
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function getAccount(): Account
    {
        $response = $this->transport->send('GET', $this->baseUrl . '/api/account', [
            'Authorization' => 'Bearer ' . $this->authKey,
            'Accept' => 'application/json',
        ]);
        $response->requireSuccess();

        return Account::fromArray($response->json());
    }
}
