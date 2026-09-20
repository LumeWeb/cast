<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Environment;

/**
 * A safe operator-facing report about one environment variable. It always names
 * the variable and the failure class but never the value that was read, so a
 * problem can be rendered directly without leaking secret material.
 */
final class EnvProblem
{
    public function __construct(
        private readonly string $variable,
        private readonly EnvProblemKind $kind,
    ) {
    }

    /**
     * The exact environment variable name this problem refers to.
     */
    public function variable(): string
    {
        return $this->variable;
    }

    /**
     * The failure class: missing, empty, malformed or inconsistent.
     */
    public function kind(): EnvProblemKind
    {
        return $this->kind;
    }

    /**
     * A plain-language, value-free message suitable for an operator-facing card.
     */
    public function message(): string
    {
        $article = $this->variable;

        return match ($this->kind) {
            EnvProblemKind::Missing => "{$article} is not set in the deployment environment",
            EnvProblemKind::Empty => "{$article} is set but empty in the deployment environment",
            EnvProblemKind::Malformed => "{$article} is not a valid http(s) base URL",
            EnvProblemKind::Inconsistent => "{$article} must be paired with its workspace auth counterpart",
        };
    }
}
