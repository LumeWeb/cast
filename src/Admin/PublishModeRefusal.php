<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Why a publish-mode change was refused, as a typed, JSON-safe value.
 *
 * The admin UI can render the refusal code directly. A refused change is
 * always side-effect free: the persisted mode is left untouched.
 */
enum PublishModeRefusal: string
{
    /** The submitted value did not name a known {@see PublishMode}. */
    case InvalidMode = 'invalid_mode';
}
