<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

/**
 * What URL resolution decided for a raw reference inside a document:
 *  - skip: leave the value exactly as written, never queue (fragment-only,
 *    data/javascript/mailto/tel and unknown schemes, empty values);
 *  - web: an absolute http(s) URL that canonicalization and the origin check
 *    may examine for queueing and rewriting.
 */
final class UrlResolution
{
    private function __construct(
        private readonly bool $skip,
        private readonly string $value,
    ) {
    }

    public static function skip(string $original): self
    {
        return new self(true, $original);
    }

    public static function web(string $absolute): self
    {
        return new self(false, $absolute);
    }

    public function isSkip(): bool
    {
        return $this->skip;
    }

    public function value(): string
    {
        return $this->value;
    }
}
