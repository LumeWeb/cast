<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Onboarding;

/**
 * The coarse lifecycle of the selected page builder within the wizard.
 *
 * Pure data — a closed set of status identifiers persisted on the aggregate.
 * It carries no behaviour; install/activation result recording is driven by the
 * transition graph in the service.
 */
enum InstallStatus: string
{
    case Idle = 'idle';
    case Installing = 'installing';
    case Installed = 'installed';
    case Activating = 'activating';
    case Active = 'active';
    case Failed = 'failed';
}
