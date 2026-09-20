<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Persistence;

/**
 * Minimal wpdb wrapper for SQL-backed persistence, mirroring {@see OptionGateway}.
 *
 * Exposes only the statements the durable work-item queue needs, so the
 * repository never touches the WordPress database global and stays fully
 * unit-testable through a fake gateway. Statements use %s / %d placeholders
 * with positional bound values; identifiers (table names) are embedded by the
 * call site, never bound, exactly like wpdb.
 */
interface WpDbGateway
{
    /**
     * Run a statement and return the number of affected rows (0 when none).
     *
     * @param list<string|int> $params
     */
    public function query(string $sql, array $params = []): int;

    /**
     * The first column of the first row, or null when there is no row.
     *
     * @param list<string|int> $params
     */
    public function getVar(string $sql, array $params = []): mixed;

    /**
     * The full first row as an assoc array, or null when there is no row.
     *
     * @param list<string|int> $params
     * @return array<string, mixed>|null
     */
    public function getRow(string $sql, array $params = []): ?array;
}
