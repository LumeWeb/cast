<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Portal;

use LumeWeb\Cast\Environment\EnvIdentity;
use LumeWeb\Cast\Http\HttpException;
use LumeWeb\Cast\Http\HttpTransport;

/**
 * Facade over the portal account + workspace-resolve clients used for runtime
 * self-identification. It uses EnvIdentity to select PORTAL_API_URL /
 * PORTAL_API_KEY, resolves the account via GetAccount, and resolves THE
 * workspace via Workspaces.Resolve (GET /api/workspaces/resolve?resource_uuid=...)
 * using the Coolify-injected COOLIFY_RESOURCE_UUID — avoiding the fragile
 * exactly-one Workspaces.List behavior entirely. A missing resource UUID is a
 * safe, value-free configuration failure that makes no network request.
 *
 * PORTAL_API_URL is the CANONICAL portal base (e.g. https://pinner.xyz); the
 * account/auth exchange + GetAccount are routed to the derived account base
 * ({@see PortalIdentity::accountBaseUrl()}, account.pinner.xyz) and the
 * IPFS/workspace resolve is routed to the derived IPFS base
 * ({@see PortalIdentity::ipfsBaseUrl()}, ipfs.pinner.xyz) — no single base is
 * reused for both services.
 *
 * PORTAL_API_KEY is the workspace API key (aud=api) the portal injects. The
 * account service only accepts a login-purpose auth key (aud=login) on
 * /api/account, so selfIdentity first exchanges the API key for an auth key via
 * POST /api/auth/key (PortalAuthKeyClient) and uses that returned bearer for
 * GetAccount. Workspaces.Resolve is an ipfs-plugin endpoint that accepts either
 * key kind, so it keeps using the PORTAL_API_KEY bearer.
 *
 * Every failure is a SelfIdentification carrying a safe, actionable,
 * value-free error (never the API key, never the exchanged auth key, never
 * response bodies, never the workspace credentials).
 */
final class PortalFacade
{
    public function __construct(
        private readonly EnvIdentity $identity,
        private readonly HttpTransport $transport,
    ) {
    }

    public function selfIdentify(): SelfIdentification
    {
        $portal = $this->identity->resolve();
        if ($portal === null) {
            return SelfIdentification::fromEnvProblems($this->identity->problems());
        }

        if ($portal->resourceUuid() === null) {
            $problems = $this->identity->problems();
            $resourceProblem = $this->identity->resourceUuidProblem();
            if ($resourceProblem !== null) {
                $problems[] = $resourceProblem;
            }

            return SelfIdentification::fromEnvProblems($problems);
        }

        // Workspaces.Resolve is a portal-plugin-ipfs endpoint, so it is routed
        // to the derived IPFS/workspace API base (ipfs.pinner.xyz) like every
        // publish/upload/website client, still over the PORTAL_API_KEY bearer.
        $resolveClient = new WorkspaceResolveClient($this->transport, $portal->ipfsBaseUrl(), $portal->accountKey());

        try {
            // The account service (account.pinner.xyz) only accepts a
            // login-purpose auth key on /api/account, so exchange the workspace
            // API key for one first — routed to the derived account base like
            // every /api/auth/* and /api/account call.
            $authKey = (new PortalAuthKeyClient($this->transport, $portal->accountBaseUrl()))
                ->exchange($portal->accountKey());
        } catch (HttpException $e) {
            return SelfIdentification::fromHttpError($portal, $e);
        }

        $accountClient = new PortalAccountClient($this->transport, $portal->accountBaseUrl(), $authKey);

        try {
            $account = $accountClient->getAccount();
        } catch (HttpException $e) {
            return SelfIdentification::fromHttpError($portal, $e);
        }

        try {
            $resolved = $resolveClient->resolve($portal->resourceUuid());
        } catch (HttpException $e) {
            return SelfIdentification::fromResolveError($portal, $account, $e);
        }

        $workspace = $resolved->workspace();
        if ($workspace === null) {
            return SelfIdentification::resolveRejected($portal, $account);
        }

        return SelfIdentification::resolved($portal, $account, $workspace, $resolved->website());
    }
}
