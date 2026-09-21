<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Why an explicit first-publish start was refused, as a typed, JSON-safe value.
 *
 * Each case names a single precondition that failed. The setup service refuses
 * before it ever creates a run or schedules a tick, so a refusal is always
 * side-effect free — the admin UI can render the refusal code directly.
 */
enum PublishStartRefusal: string
{
    /** The deployment environment does not resolve to a complete identity. */
    case EnvIdentityMissing = 'env_identity_missing';

    /** Onboarding has not reached a terminal state (Completed or Skipped). */
    case OnboardingNotComplete = 'onboarding_not_complete';

    /** No publish-eligible public content has been discovered yet. */
    case NoEligibleContent = 'no_eligible_content';

    /** A run is already live (queued, running or paused); refuse rather than stack. */
    case RunActive = 'run_active';

    /** A website/IPNS identity already exists; this is no longer a first publish. */
    case IdentityConflict = 'identity_conflict';
}
