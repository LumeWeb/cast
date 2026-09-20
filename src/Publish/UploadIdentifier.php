<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * The opaque identifier a successful upload returns; the result endpoint is
 * polled with it until a terminal status arrives.
 */
final class UploadIdentifier
{
    public function __construct(
        public readonly string $value,
    ) {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
