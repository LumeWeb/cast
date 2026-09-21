<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Portal\SelfIdentification;

/**
 * The dashboard Connection card view model — a safe, display-only mapping of
 * the resolved account/workspace self-identification (or its failure),
 * including the workspace lifecycle status and the derived Website publish
 * state.
 *
 * Every value the template renders is pinned here from a {@see
 * SelfIdentification}: the state (resolved | config | error), the account
 * name/email/verification, the workspace label/domain/status and the derived
 * website publish state (none | pending | published) when resolved, or a
 * value-free error message naming env variables/failure classes. Credentials
 * never enter this view: it holds only identifiers and statuses plus the safe
 * error, so they cannot reach the template, the serialized status or the logs.
 * The workspace URL and resource UUID (deployment references) are never
 * rendered here.
 */
final class ConnectionView
{
    public const STATE_RESOLVED = 'resolved';
    public const STATE_CONFIG = 'config';
    public const STATE_ERROR = 'error';

    private function __construct(
        private readonly string $state,
        private readonly ?string $accountName,
        private readonly ?string $accountEmail,
        private readonly ?bool $verified,
        private readonly ?string $workspaceLabel,
        private readonly ?string $workspaceDomain,
        private readonly ?string $workspaceStatus,
        private readonly ?string $websiteState,
        private readonly ?string $error,
    ) {
    }

    public static function fromSelfIdentification(SelfIdentification $self): self
    {
        if ($self->isResolved()) {
            $account = $self->account();
            $workspace = $self->workspace();

            return new self(
                self::STATE_RESOLVED,
                $account === null ? null : trim($account->firstName() . ' ' . $account->lastName()),
                $account?->email(),
                $account?->verified(),
                $workspace?->label(),
                $workspace?->domain(),
                $workspace?->status(),
                $self->publishState(),
                null,
            );
        }

        return new self(
            // An operator-facing configuration problem (missing/empty/malformed
            // env vars) is a "config" state; any other failure (resolve
            // rejection, HTTP/decoding) is a plain error.
            $self->problems() !== [] ? self::STATE_CONFIG : self::STATE_ERROR,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $self->error(),
        );
    }

    public function state(): string
    {
        return $this->state;
    }

    public function isResolved(): bool
    {
        return $this->state === self::STATE_RESOLVED;
    }

    public function accountName(): ?string
    {
        return $this->accountName;
    }

    public function accountEmail(): ?string
    {
        return $this->accountEmail;
    }

    public function verified(): ?bool
    {
        return $this->verified;
    }

    public function workspaceLabel(): ?string
    {
        return $this->workspaceLabel;
    }

    public function workspaceDomain(): ?string
    {
        return $this->workspaceDomain;
    }

    /**
     * The workspace lifecycle status (e.g. active), identifiers only.
     */
    public function workspaceStatus(): ?string
    {
        return $this->workspaceStatus;
    }

    /**
     * The derived Website publish state: 'none' | 'pending' | 'published'
     * (matching {@see \LumeWeb\Cast\Portal\WorkspaceResolve::publishState()}).
     */
    public function websiteState(): ?string
    {
        return $this->websiteState;
    }

    /**
     * Safe, value-free error message for the Connection card, or null when resolved.
     */
    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * @return array<string, mixed> JSON-safe identifiers only (no credentials).
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'account_name' => $this->accountName,
            'account_email' => $this->accountEmail,
            'verified' => $this->verified,
            'workspace_label' => $this->workspaceLabel,
            'workspace_domain' => $this->workspaceDomain,
            'workspace_status' => $this->workspaceStatus,
            'website_state' => $this->websiteState,
            'error' => $this->error,
        ];
    }
}
