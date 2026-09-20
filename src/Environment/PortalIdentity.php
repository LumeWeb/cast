<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Environment;

/**
 * Deterministic self-identification configuration derived from the deployment
 * environment, ready for the portal calls.
 *
 * PORTAL_API_URL is the CANONICAL portal base (e.g. https://pinner.xyz); the
 * account/auth and IPFS/workspace/publish service bases are derived from it by
 * {@see PortalEndpoints} — {@see accountBaseUrl()} (account.pinner.xyz) and
 * {@see ipfsBaseUrl()} (ipfs.pinner.xyz). PORTAL_API_KEY is the workspace API
 * key (aud=api). GetAccount on the account host only accepts a login-purpose
 * auth key, so PortalFacade exchanges the API key for an auth key
 * (POST /api/auth/key) first and uses that for GetAccount; Workspaces.Resolve
 * stays on the same PORTAL_API_KEY bearer (the ipfs endpoint accepts either key
 * kind).
 *
 * Immutable by construction and value-comparable (no identity), so the same
 * environment always yields the same configuration. The API key and optional
 * workspace credentials are exposed only through intentional accessors and are
 * never included in any rendered/logged representation.
 */
final class PortalIdentity
{
    private readonly ?PortalEndpoints $endpoints;

    public function __construct(
        private readonly string $portalBaseUrl,
        private readonly string $accountKey,
        private readonly ?WorkspaceAuth $workspaceAuth = null,
        private readonly ?string $workspaceUrl = null,
        private readonly ?string $resourceUuid = null,
    ) {
        $this->endpoints = PortalEndpoints::parse($this->portalBaseUrl);
    }

    /**
     * Normalized canonical portal base URL (trimmed, trailing slash removed),
     * e.g. https://pinner.xyz.
     */
    public function portalBaseUrl(): string
    {
        return $this->portalBaseUrl;
    }

    /**
     * The account/auth service base URL derived from the canonical base
     * (e.g. https://account.pinner.xyz) — GetAccount and the /api/auth/* key
     * exchange. Falls back to the canonical base when it cannot be parsed (the
     * safe unreachable-identity path builds an empty identity).
     */
    public function accountBaseUrl(): string
    {
        return $this->endpoints?->accountBaseUrl() ?? $this->portalBaseUrl;
    }

    /**
     * The IPFS/workspace/publish API service base URL derived from the
     * canonical base (e.g. https://ipfs.pinner.xyz) — websites, uploads, IPNS,
     * domain and workspace-resolve endpoints. Falls back to the canonical base
     * when it cannot be parsed.
     */
    public function ipfsBaseUrl(): string
    {
        return $this->endpoints?->ipfsBaseUrl() ?? $this->portalBaseUrl;
    }

    /**
     * Account API key (PORTAL_API_KEY, aud=api) used as the bearer for
     * Workspaces.Resolve and every publish/domain/upload client. GetAccount on
     * account.pinner.xyz instead uses the login-purpose auth key obtained by
     * exchanging this key (see PortalAuthKeyClient / PortalFacade).
     */
    public function accountKey(): string
    {
        return $this->accountKey;
    }

    /**
     * Workspace-scoped credentials when the workspace auth pair was provided.
     */
    public function workspaceAuth(): ?WorkspaceAuth
    {
        return $this->workspaceAuth;
    }

    /**
     * This workspace's portal-injected public URL (PORTAL_WORKSPACE_URL),
     * provided for reference/loopback context only — never exposed in any
     * rendered/logged representation.
     */
    public function workspaceUrl(): ?string
    {
        return $this->workspaceUrl;
    }

    /**
     * The portal-injected Coolify application/resource UUID
     * (COOLIFY_RESOURCE_UUID) used to resolve this deployment's workspace
     * identity via GET /api/workspaces/resolve. Null when the container was
     * not injected with one (the runtime-identity resolve path cannot run).
     */
    public function resourceUuid(): ?string
    {
        return $this->resourceUuid;
    }
}
