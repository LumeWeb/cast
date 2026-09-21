<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Why a guided website-card action (create / link / list) was refused, as a
 * typed, JSON-safe value.
 *
 * Each case names a single precondition that failed. The setup service refuses
 * before it ever touches the website registry or workspace linker, so a
 * refusal is always side-effect free, and the request_failed cases never echo
 * the wrapped exception message — only the fixed code — so credentials can
 * never leak through a payload.
 */
enum PublishWebsiteRefusal: string
{
    /** No run is parked awaiting a website (nothing to create/link for). */
    case NotAwaitingWebsite = 'not_awaiting_website';

    /** The portal adapters (registry / linker) are not wired; identity is incomplete. */
    case Unavailable = 'unavailable';

    /** The workspace could not be resolved for the link action. */
    case NoWorkspace = 'no_workspace';

    /** The supplied website id is missing or not a positive integer. */
    case InvalidWebsiteId = 'invalid_website_id';

    /** The portal rejected the explicit create (message never surfaced). */
    case CreateFailed = 'create_failed';

    /** The portal rejected the attach (message never surfaced). */
    case LinkFailed = 'link_failed';

    /** The attach 409s: the chosen website already belongs to another workspace. */
    case WebsiteAlreadyLinked = 'website_already_linked';

    /** The attach 409s: the workspace already holds a DIFFERENT website. */
    case WorkspaceAlreadyLinked = 'workspace_already_linked';

    /** The account website list could not be read (message never surfaced). */
    case ListFailed = 'list_failed';
}
