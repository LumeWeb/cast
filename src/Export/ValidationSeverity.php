<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Severity of a single validation finding. Hard findings fail the validation; warning
 * findings produce completed_with_warnings and may be escalated by strict mode.
 */
enum ValidationSeverity: string
{
    case Hard = 'hard';
    case Warning = 'warning';
}
