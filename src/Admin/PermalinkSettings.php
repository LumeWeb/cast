<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

/**
 * Wrapper around the WordPress permalink-structure option and the one-time
 * rewrite-rule flush, so the {@see PermalinkGuard} enforcement stays
 * unit-testable without a live WordPress runtime.
 *
 * The exported surface is deliberately the smallest the guard needs: read the
 * current structure, write the enforced canonical structure, remember whether
 * the one-time hard flush already ran (to avoid per-request churn), and
 * perform that hard flush.
 */
interface PermalinkSettings
{
    /**
     * The current permalink_structure option value, or '' when plain `?p=`
     * permalinks are in use.
     */
    public function structure(): string;

    /** Persist the given permalink_structure option value. */
    public function setStructure(string $structure): void;

    /**
     * Whether the one-time hard rewrite-rule flush already ran after an
     * enforcement. Persisted so a later admin request never flushes again.
     */
    public function hasHardFlushed(): bool;

    /** Record that the one-time hard flush is done. */
    public function markHardFlushed(): void;

    /** Hard-flush the rewrite rules now (production: flush_rewrite_rules). */
    public function flushRewriteRules(): void;
}
