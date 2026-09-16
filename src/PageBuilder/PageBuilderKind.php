<?php

declare(strict_types=1);

namespace LumeWeb\Cast\PageBuilder;

/**
 * The role a curated page-builder candidate plays in the onboarding guidance.
 *
 * Kinds are coarse and stable: native core editor, a full visual builder, a
 * lightweight block toolkit, an alternative/fallback, and (reserved)
 * comparison-only metadata that is never offered for installation.
 */
enum PageBuilderKind: string
{
    case Native = 'native';
    case VisualBuilder = 'visual_builder';
    case BlockToolkit = 'block_toolkit';
    case Alternative = 'alternative';
    case Comparison = 'comparison';
}
