<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Why an admin domain-setup operation was refused, as a typed, JSON-safe value.
 *
 * Each case names a single precondition that failed. The setup service refuses
 * before it ever touches the DomainClient, so a refusal is always side-effect
 * free, and the request_failed case never echoes the wrapped exception message
 * — only the fixed code — so credentials can never leak through a payload.
 */
enum DomainRefusal: string
{
    /** The deployment environment does not resolve to a complete identity. */
    case EnvIdentityMissing = 'env_identity_missing';

    /** No website identity is registered yet; the site-locked ops need one. */
    case IdentityMissing = 'identity_missing';

    /** The supplied domain name is blank. */
    case InvalidDomain = 'invalid_domain';

    /** The supplied namespace is not one the portal supports (icann, hns). */
    case InvalidNamespace = 'invalid_namespace';

    /** The supplied binding id is blank. */
    case InvalidDomainId = 'invalid_domain_id';

    /** The supplied platform subdomain label is blank. */
    case InvalidLabel = 'invalid_label';

    /** The domain client failed the request (message never surfaced). */
    case RequestFailed = 'request_failed';
}
