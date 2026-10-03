<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * The irreversible address source selected before a site's first deployment.
 */
enum PublishDestinationSource: string
{
    case Platform = 'platform';
    case Custom = 'custom';
    case Existing = 'existing';
}
