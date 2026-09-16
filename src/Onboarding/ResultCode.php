<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Onboarding;

/**
 * Structured, machine-readable outcomes for a builder install/activation.
 *
 * The frontend only ever reports success or failure; the backend records the
 * matching structured code. Unknown/absent codes stay null on the aggregate so
 * recording a result always overwrites a previous one with a known value.
 */
enum ResultCode: string
{
    case InstallSucceeded = 'install_succeeded';
    case InstallFailed = 'install_failed';
    case ActivateSucceeded = 'activate_succeeded';
    case ActivateFailed = 'activate_failed';
}
