<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Why an explicit publish-existing retry was refused, as a typed, JSON-safe
 * value.
 *
 * Each case names a single precondition that failed. The setup service
 * refuses before it ever seeds a run or schedules a tick, so a refusal is
 * always side-effect free — the admin UI can render the refusal code directly.
 */
enum PublishExistingRefusal: string
{
    /** The deployment environment does not resolve to a complete identity. */
    case EnvIdentityMissing = 'env_identity_missing';

    /** Onboarding has not reached a terminal state (Completed or Skipped). */
    case OnboardingNotComplete = 'onboarding_not_complete';

    /** No intact packed artifact exists to reuse (no run, no pack, or empty zip path). */
    case NoArtifact = 'no_artifact';

    /** A run is already live (queued, running or superseded); refuse rather than stack. */
    case RunActive = 'run_active';

    /** No website/IPNS identity yet: a re-publish cannot target anything. */
    case IdentityMissing = 'identity_missing';
}
