<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Configurable strictness of the pre-pack validation. In warning mode
 * non-fatal findings (leftover origins, broken local references) let the run
 * finish as completed_with_warnings while still producing a diagnostic ZIP; in
 * strict mode warnable origin leftovers escalate to a hard failure that blocks
 * packing.
 */
enum ArtifactValidationMode: string
{
    case Warning = 'warning';
    case Strict = 'strict';
}
