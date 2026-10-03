<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * A deliberate, side-effect-free provisioning refusal: the run's confirmed
 * destination cannot be provisioned (no destination at all, an existing
 * website that is already attached elsewhere, or an attach that is
 * unavailable). Carries a FIXED, friendly message — never a wrapped
 * exception text — so the publish boundary can surface it as a plain
 * failure without leaking internals.
 */
final class WebsiteProvisioningException extends \RuntimeException
{
}
