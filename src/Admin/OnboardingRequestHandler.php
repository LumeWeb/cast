<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Onboarding\InvalidBuilder;
use LumeWeb\Cast\Onboarding\InvalidTransition;
use LumeWeb\Cast\Onboarding\Wizard;
use LumeWeb\Cast\Onboarding\WizardService;

/**
 * Generic, capability + nonce gated backend contracts for wizard mutations.
 *
 * Every mutation is gated by both the caller's capability and a valid nonce for
 * the posted action before any state is touched. Building the wizard state is
 * delegated to WizardService, which additionally enforces the catalog allowlist
 * (selectBuilder) and Finite transition legality for every mutation. Invalid
 * builder selections and check failures are denied (403); otherwise the request
 * redirects back to the admin page. Deny/redirect are delegated to the
 * RequestContext so this policy is testable and never skipped.
 */
final class OnboardingRequestHandler
{
    public function __construct(
        private readonly WizardService $wizard,
        private readonly RequestContext $context,
    ) {
    }

    public function start(string $nonceAction, string $capability, string $pageSlug): void
    {
        $this->gated(fn (): Wizard => $this->wizard->start(), $nonceAction, $capability, $pageSlug);
    }

    public function skip(string $nonceAction, string $capability, string $pageSlug): void
    {
        $this->gated(fn (): Wizard => $this->wizard->skip(), $nonceAction, $capability, $pageSlug);
    }

    public function complete(string $nonceAction, string $capability, string $pageSlug): void
    {
        $this->gated(fn (): Wizard => $this->wizard->complete(), $nonceAction, $capability, $pageSlug);
    }

    public function selectBuilder(string $nonceAction, string $capability, string $pageSlug): void
    {
        $this->gated(
            function (): Wizard {
                $slug = $this->context->requestParam('builder');

                return $this->wizard->selectBuilder(is_string($slug) ? $slug : '');
            },
            $nonceAction,
            $capability,
            $pageSlug,
        );
    }

    public function recordInstall(string $nonceAction, string $capability, string $pageSlug): void
    {
        $this->gated(
            fn (): Wizard => $this->wizard->recordInstall($this->requestBool('success')),
            $nonceAction,
            $capability,
            $pageSlug,
        );
    }

    public function recordActivate(string $nonceAction, string $capability, string $pageSlug): void
    {
        $this->gated(
            fn (): Wizard => $this->wizard->recordActivate($this->requestBool('success')),
            $nonceAction,
            $capability,
            $pageSlug,
        );
    }

    public function resetBuilder(string $nonceAction, string $capability, string $pageSlug): void
    {
        $this->gated(fn (): Wizard => $this->wizard->resetBuilder(), $nonceAction, $capability, $pageSlug);
    }

    public function reopen(string $nonceAction, string $capability, string $pageSlug): void
    {
        $this->gated(fn (): Wizard => $this->wizard->reopen(), $nonceAction, $capability, $pageSlug);
    }

    /**
     * Shared check: capability + nonce, then the mutation, then a redirect.
     * Invalid builder selections (not on the allowlist) are denied, never
     * recorded.
     *
     * An invalid transition is a state conflict from a stale/crafted form, not
     * an authorization failure: the guard has already passed. Rather than let a
     * Finite exception bubble up into a fatal admin-post page, it is converted
     * to a redirect back to the wizard, which re-renders the real current state
     * as the useful feedback. Guards are never weakened and the domain exception
     * is never swallowed silently — the mutation simply did not happen.
     */
    private function gated(
        callable $mutate,
        string $nonceAction,
        string $capability,
        string $pageSlug,
    ): void {
        $nonce = $this->context->requestNonce();

        if (
            !$this->context->isCurrentUserAllowed($capability)
            || !$this->context->verifyNonce($nonce, $nonceAction)
        ) {
            $this->context->deny();

            return;
        }

        try {
            $mutate();
        } catch (InvalidBuilder) {
            $this->context->deny();

            return;
        } catch (InvalidTransition) {
            $this->context->redirectToAdminPage($pageSlug);

            return;
        }

        $this->context->redirectToAdminPage($pageSlug);
    }

    private function requestBool(string $key): bool
    {
        return in_array(
            $this->context->requestParam($key),
            ['1', 'true', 1, true],
            true,
        );
    }
}
