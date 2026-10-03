<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Why a destination setup action (save / confirm) was refused, as a typed,
 * JSON-safe value.
 *
 * Each case names a single precondition that failed. The setup service refuses
 * before it ever writes the destination option, so a refusal is always
 * side-effect free, and the codes are fixed (never a wrapped exception
 * message) so internals can never leak through a payload.
 */
enum PublishDestinationRefusal: string
{
    /** The destination store is not wired (incomplete composition). */
    case StoreUnavailable = 'store_unavailable';

    /** The request does not describe a legal destination (unknown source,
     * missing required field, or an impossible field combination). */
    case InvalidInput = 'invalid_input';

    /** A confirmed destination cannot be changed (only re-confirmed unchanged). */
    case ConfirmedCannotChange = 'confirmed_cannot_change';

    /** The website was already created or attached; the address is final. */
    case CreatedOrAttachedCannotChange = 'created_or_attached_cannot_change';

    /** The chosen existing website is already attached to a workspace (the
     * attach API's 409): it is in use elsewhere and cannot be the site's
     * address — the operator must pick a different website. */
    case WebsiteAlreadyAttached = 'website_already_attached';

    /** Attaching the chosen existing website failed for a non-conflict
     * reason (transport/portal): the choice was NOT confirmed, and the
     * action can be retried. */
    case AttachFailed = 'attach_failed';
}
