<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Environment;

/**
 * Machine-readable classification of an environment-identity problem so the
 * wizard can render a distinct, actionable card per class: a variable was not
 * set at all (Missing), was set but empty (Empty), was set to an invalid value
 * (Malformed), or a dependent pair was only partially provided (Inconsistent).
 */
enum EnvProblemKind: string
{
    case Missing = 'missing';
    case Empty = 'empty';
    case Malformed = 'malformed';
    case Inconsistent = 'inconsistent';
}
