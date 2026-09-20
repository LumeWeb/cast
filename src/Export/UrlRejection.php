<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Machine-readable reason a URL failed canonicalization, carried by InvalidUrl
 * so callers can decide between skip-with-record and hard failure.
 */
enum UrlRejection: string
{
    case Empty = 'empty';
    case Relative = 'relative';
    case Malformed = 'malformed';
    case NonHttpScheme = 'non_http_scheme';
    case UserInfo = 'user_info';
    case NulByte = 'nul_byte';
    case ControlCharacter = 'control_character';
    case Backslash = 'backslash';

    /**
     * A literal `*` in the path or query marks a glob/pattern pseudo-URL (e.g.
     * `/wp-*.php`, `/wp-admin/*`) that never identifies a single exportable
     * resource. Only a raw `*` is rejected; the percent-encoded `%2A` stays
     * allowed because it can legitimately appear in a real URL.
     */
    case Wildcard = 'wildcard';
}
