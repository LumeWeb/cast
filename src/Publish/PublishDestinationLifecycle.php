<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * Lifecycle of the destination setup itself, kept deliberately separate from
 * run/deployment status. A draft can be edited freely; a confirmed choice is
 * frozen until the website is created or attached; created_or_attached is
 * terminal — the address cannot change any more (there is no detach API).
 */
enum PublishDestinationLifecycle: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case CreatedOrAttached = 'created_or_attached';
}
