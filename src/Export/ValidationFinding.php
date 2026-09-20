<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * One deterministic pre-pack validation finding: the category token, severity,
 * human message, and — when applicable — the offending artifact path and the
 * raw reference/path that triggered it, so the manifest can point at samples.
 */
final class ValidationFinding
{
    public function __construct(
        public readonly string $category,
        public readonly ValidationSeverity $severity,
        public readonly string $message,
        public readonly ?string $path = null,
        public readonly ?string $reference = null,
    ) {
    }

    public function escalated(): self
    {
        if ($this->severity === ValidationSeverity::Hard) {
            return $this;
        }

        return new self($this->category, ValidationSeverity::Hard, $this->message, $this->path, $this->reference);
    }
}
