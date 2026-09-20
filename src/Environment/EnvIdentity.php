<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Environment;

/**
 * Typed reader for the deployment identity (auth/configuration/bootstrap).
 *
 * Reads the Coolify-injected environment variables that make up the deployment identity, validates
 * them, and produces either a list of safe problems or a deterministic
 * PortalIdentity for the account self-identification calls (GetAccount +
 * Workspaces.Resolve; GetAccount uses a login-purpose auth key exchanged from
 * the PORTAL_API_KEY API key, see PortalFacade). PORTAL_API_URL is the CANONICAL
 * portal base (e.g. https://pinner.xyz); the account/auth and IPFS/workspace/
 * publish service bases are derived from it (see {@see PortalEndpoints}).
 *
 * Security invariants:
 *  - values are read once and held in memory for the run only; nothing is
 *    persisted, written to options/transients, or logged by this reader;
 *  - credentials are only ever reachable through the explicit accessors on the
 *    resolved PortalIdentity / WorkspaceAuth value objects;
 *  - problem messages name the environment variable and the failure class, but
 *    never the value that was read.
 */
final class EnvIdentity
{
    public const PORTAL_API_URL = 'PORTAL_API_URL';
    public const PORTAL_API_KEY = 'PORTAL_API_KEY';
    public const WORKSPACE_AUTH_USERNAME = 'WORKSPACE_AUTH_USERNAME';
    public const WORKSPACE_AUTH_PASSWORD = 'WORKSPACE_AUTH_PASSWORD';
    /**
     * The workspace's portal-injected public URL, for reference/loopback
     * context only (PORTAL_WORKSPACE_URL). The portal injects this variable,
     * not the legacy COOLIFY_URL.
     */
    public const PORTAL_WORKSPACE_URL = 'PORTAL_WORKSPACE_URL';
    /**
     * The portal-injected Coolify application/resource UUID that lets a
     * runtime container resolve its own workspace identity via
     * GET /api/workspaces/resolve?resource_uuid=... .
     */
    public const COOLIFY_RESOURCE_UUID = 'COOLIFY_RESOURCE_UUID';

    public function __construct(private readonly EnvReader $env)
    {
    }

    /**
     * Reader backed by the real process environment (getenv()).
     */
    public static function fromEnvironment(): self
    {
        return new self(new NativeEnvReader());
    }

    /**
     * The environment variables that must be present for the identity to be
     * complete, in deterministic order.
     *
     * @return list<string>
     */
    public static function requiredVariableNames(): array
    {
        return [self::PORTAL_API_URL, self::PORTAL_API_KEY];
    }

    /**
     * The optional environment variables (a workspace auth pair plus the
     * deployment reference URL), in deterministic order.
     *
     * @return list<string>
     */
    public static function optionalVariableNames(): array
    {
        return [self::WORKSPACE_AUTH_USERNAME, self::WORKSPACE_AUTH_PASSWORD, self::PORTAL_WORKSPACE_URL];
    }

    /**
     * Whether the deployment identity is complete (no problems).
     */
    public function isComplete(): bool
    {
        return $this->problems() === [];
    }

    /**
     * Deterministic, safe problems in a stable order. Never contains values.
     *
     * @return list<EnvProblem>
     */
    public function problems(): array
    {
        $problems = [];

        $url = $this->env->get(self::PORTAL_API_URL);
        if ($url === false) {
            $problems[] = new EnvProblem(self::PORTAL_API_URL, EnvProblemKind::Missing);
        } elseif (trim($url) === '') {
            $problems[] = new EnvProblem(self::PORTAL_API_URL, EnvProblemKind::Empty);
        } elseif (self::normalizedPortalBaseUrl($url) === null) {
            $problems[] = new EnvProblem(self::PORTAL_API_URL, EnvProblemKind::Malformed);
        }

        $key = $this->env->get(self::PORTAL_API_KEY);
        if ($key === false) {
            $problems[] = new EnvProblem(self::PORTAL_API_KEY, EnvProblemKind::Missing);
        } elseif (trim($key) === '') {
            $problems[] = new EnvProblem(self::PORTAL_API_KEY, EnvProblemKind::Empty);
        }

        if ($this->workspaceAuthProblem() !== null) {
            $problems[] = $this->workspaceAuthProblem();
        }

        return $problems;
    }

    /**
     * Resolve the deterministic self-identification configuration, or null when
     * the environment is incomplete. Credentials are only reachable through the
     * returned PortalIdentity's intentional accessors.
     */
    public function resolve(): ?PortalIdentity
    {
        if (!$this->isComplete()) {
            return null;
        }

        $url = (string) $this->env->get(self::PORTAL_API_URL);
        $key = trim((string) $this->env->get(self::PORTAL_API_KEY));

        $username = $this->nonEmpty(self::WORKSPACE_AUTH_USERNAME);
        $password = $this->nonEmpty(self::WORKSPACE_AUTH_PASSWORD);
        $workspaceAuth = ($username !== null && $password !== null)
            ? new WorkspaceAuth($username, $password)
            : null;

        $workspaceUrl = $this->nonEmpty(self::PORTAL_WORKSPACE_URL);
        $resourceUuid = $this->nonEmpty(self::COOLIFY_RESOURCE_UUID);

        return new PortalIdentity(
            self::normalizedPortalBaseUrl($url) ?? $url,
            $key,
            $workspaceAuth,
            $workspaceUrl,
            $resourceUuid,
        );
    }

    /**
     * A safe, value-free problem for the portal-injected
     * COOLIFY_RESOURCE_UUID when it is missing or blank — the prerequisite for
     * the workspace runtime-identity resolve call. null when the UUID is set.
     *
     * Deliberately NOT part of {@see problems()}: the publish stack only needs
     * PORTAL_API_URL + PORTAL_API_KEY, so a missing resource UUID must not
     * break the publish identity. The resolve/runtime-identity path consults
     * this separately.
     */
    public function resourceUuidProblem(): ?EnvProblem
    {
        $value = $this->env->get(self::COOLIFY_RESOURCE_UUID);
        if ($value === false) {
            return new EnvProblem(self::COOLIFY_RESOURCE_UUID, EnvProblemKind::Missing);
        }
        if (trim($value) === '') {
            return new EnvProblem(self::COOLIFY_RESOURCE_UUID, EnvProblemKind::Empty);
        }

        return null;
    }

    /**
     * Trim, validate and normalize the portal API URL to the canonical http(s)
     * base (the TLD root that {@see PortalEndpoints} derives the account and
     * ipfs service bases from, e.g. https://pinner.xyz).
     *
     * Delegates validation to {@see PortalEndpoints::parse()}: null when the
     * value is not an absolute http(s) URL with a host, or when it embeds
     * credentials in the userinfo component (a base URL must never carry
     * credentials to the HTTP client).
     */
    private static function normalizedPortalBaseUrl(string $value): ?string
    {
        return PortalEndpoints::parse($value)?->canonicalBaseUrl();
    }

    /**
     * A value trimmed to null when absent or blank.
     */
    private function nonEmpty(string $name): ?string
    {
        $value = $this->env->get($name);
        if ($value === false) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function workspaceAuthProblem(): ?EnvProblem
    {
        $username = $this->nonEmpty(self::WORKSPACE_AUTH_USERNAME);
        $password = $this->nonEmpty(self::WORKSPACE_AUTH_PASSWORD);

        if (($username === null) === ($password === null)) {
            return null;
        }

        return new EnvProblem(self::WORKSPACE_AUTH_USERNAME, EnvProblemKind::Inconsistent);
    }
}
