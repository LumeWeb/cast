<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * Thrown when a URL cannot take part in export work-item identity.
 *
 * Carries a machine-readable rejection reason so discovery and capture callers
 * can choose between skip-with-record and hard failure without parsing
 * messages.
 */
final class InvalidUrl extends \InvalidArgumentException
{
    public function __construct(
        public readonly UrlRejection $rejection,
        string $message,
    ) {
        parent::__construct($message);
    }
}
