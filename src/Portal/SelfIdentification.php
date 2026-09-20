<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Portal;

use LumeWeb\Cast\Environment\EnvProblem;
use LumeWeb\Cast\Environment\PortalIdentity;
use LumeWeb\Cast\Http\HttpException;
use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use LumeWeb\Cast\Ipfs\Workspace;

/**
 * Result of PortalFacade::selfIdentify(): either a resolved account + the
 * workspace the runtime container belongs to (via Workspaces.Resolve, which
 * also carries the optional attached Website publish relationship), or a
 * failure. Failures always carry a safe, operator-facing error message that
 * names env vars / failure classes but never credential values; the typed
 * HttpException (when one triggered the failure) stays available through
 * cause() so callers can keep the error type.
 */
final class SelfIdentification
{
    /**
     * @param list<EnvProblem> $problems
     */
    private function __construct(
        private readonly bool $resolved,
        private readonly ?PortalIdentity $portalIdentity,
        private readonly ?Account $account,
        private readonly ?Workspace $workspace,
        private readonly ?WorkspaceResolveWebsite $website,
        private readonly ?string $error,
        private readonly array $problems,
        private readonly ?HttpException $cause,
    ) {
    }

    public static function resolved(
        PortalIdentity $identity,
        Account $account,
        Workspace $workspace,
        ?WorkspaceResolveWebsite $website = null,
    ): self {
        return new self(true, $identity, $account, $workspace, $website, null, [], null);
    }

    /**
     * @param list<EnvProblem> $problems
     */
    public static function fromEnvProblems(array $problems): self
    {
        $messages = implode('; ', array_map(
            static fn (EnvProblem $problem): string => $problem->message(),
            $problems,
        ));

        return new self(false, null, null, null, null, $messages, $problems, null);
    }

    public static function fromHttpError(PortalIdentity $identity, HttpException $cause): self
    {
        return new self(false, $identity, null, null, null, self::safeHttpMessage($cause), [], $cause);
    }

    /**
     * The portal could not resolve a workspace for this resource UUID/key from
     * an unexpected transport blow-up. Value-free like every other failure.
     */
    public static function unreachable(PortalIdentity $identity): self
    {
        return new self(
            false,
            $identity,
            null,
            null,
            null,
            'Pinner could not be reached while identifying the account.',
            [],
            null,
        );
    }

    /**
     * The portal responded 200 with a server-side `error` field: no workspace
     * matched this key + resource UUID. The raw server message is never put on
     * the wire to operators — only a safe, value-free substitute.
     */
    public static function resolveRejected(PortalIdentity $identity, Account $account): self
    {
        return new self(
            false,
            $identity,
            $account,
            null,
            null,
            'No workspace matched this Pinner key and resource UUID. Check COOLIFY_RESOURCE_UUID and PORTAL_API_KEY.',
            [],
            null,
        );
    }

    /**
     * A transport/status/decoding failure while resolving the workspace maps
     * to a safe, value-free message that names the resolve config, preserving
     * the typed HttpException cause.
     */
    public static function fromResolveError(PortalIdentity $identity, Account $account, HttpException $cause): self
    {
        return new self(false, $identity, $account, null, null, self::safeResolveMessage($cause), [], $cause);
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }

    public function portalIdentity(): ?PortalIdentity
    {
        return $this->portalIdentity;
    }

    public function account(): ?Account
    {
        return $this->account;
    }

    public function workspace(): ?Workspace
    {
        return $this->workspace;
    }

    /**
     * The attached Website publish relationship when the resolve response
     * carried one (null when none is attached or when unresolved). Identifiers
     * and a lifecycle status only — never credentials.
     */
    public function website(): ?WorkspaceResolveWebsite
    {
        return $this->website;
    }

    /**
     * A safe, derived publish state from the attached Website publish
     * relationship: 'none' | 'pending' | 'published', or null when unresolved.
     */
    public function publishState(): ?string
    {
        if (!$this->resolved) {
            return null;
        }
        if ($this->website === null) {
            return WorkspaceResolve::PUBLISH_STATE_NONE;
        }

        return $this->website->isLive()
            ? WorkspaceResolve::PUBLISH_STATE_PUBLISHED
            : WorkspaceResolve::PUBLISH_STATE_PENDING;
    }

    /**
     * Safe, value-free error message for an operator-facing card, or null when resolved.
     */
    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * @return list<EnvProblem>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * The typed HTTP failure that prevented resolution, when one occurred.
     */
    public function cause(): ?HttpException
    {
        return $this->cause;
    }

    /**
     * A status/decoding/transport failure rendered without any credential, URI
     * or response detail that could leak a secret.
     */
    private static function safeHttpMessage(HttpException $cause): string
    {
        if ($cause instanceof UnexpectedStatusCodeException) {
            return sprintf(
                'Pinner rejected identity check with HTTP %d %s. Check PORTAL_API_URL and PORTAL_API_KEY.',
                $cause->status(),
                $cause->response()->reason(),
            );
        }

        if ($cause instanceof ResponseDecodingException) {
            return 'Pinner returned an unreadable response while identifying the account.';
        }

        return 'Pinner could not be reached while identifying the account.';
    }

    /**
     * A resolve failure rendered without any credential, resource-UUID or
     * response detail that could leak a secret.
     */
    private static function safeResolveMessage(HttpException $cause): string
    {
        if ($cause instanceof UnexpectedStatusCodeException) {
            return sprintf(
                'Pinner could not resolve this workspace from its resource UUID (HTTP %d %s). '
                . 'Check PORTAL_API_URL, PORTAL_API_KEY and COOLIFY_RESOURCE_UUID.',
                $cause->status(),
                $cause->response()->reason(),
            );
        }

        if ($cause instanceof ResponseDecodingException) {
            return 'Pinner returned an unreadable response while resolving the workspace.';
        }

        return 'Pinner could not be reached while resolving the workspace.';
    }
}
