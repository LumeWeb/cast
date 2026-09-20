<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Shape of a capture/rewrite work item, mirroring the kind column.
 */
enum WorkItemKind: string
{
    case Page = 'page';
    case Asset = 'asset';
    case Redirect = 'redirect';
    case Text = 'text';
}
