<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Persistence;

/**
 * Recording wpdb test double for the WordPress persistence adapters.
 *
 * Exposes exactly the wpdb public surface Cast talks to (prefix,
 * get_charset_collate, prepare, query, get_var, get_row) and records prepared
 * statements and dispatched queries so adapters can be asserted without a real
 * database. Results are scriptable per test through the public properties;
 * prepare() returns the module of the prepared-query log so each prepared
 * statement is uniquely observable in the query log.
 */
final class FakeWpDb
{
    /**
     * Every prepare() call: [query, positional args].
     *
     * @var list<array{0: string, 1: list<mixed>}>
     */
    public array $prepared = [];

    /**
     * Every query()/get_var()/get_row() dispatched SQL (already prepared).
     *
     * @var list<string>
     */
    public array $queries = [];

    public string $prefix = 'wptests_';

    /**
     * Result the next query() returns (int affected rows, or false on error).
     */
    public int|false $queryResult = 1;

    /**
     * Every update() call: [table, data, where, format, where_format].
     *
     * @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3: list<string>, 4: list<string>}>
     */
    public array $updates = [];

    /**
     * Result the next get_var() returns.
     */
    public mixed $varResult = null;

    /**
     * Result the next get_row() returns (assoc array, object or null).
     *
     * @var object|array<string, mixed>|null
     */
    public object|array|null $rowResult = null;

    /**
     * The $output argument the last get_row() call received.
     */
    public mixed $lastRowOutput = null;

    // Method names below mirror the WordPress wpdb API surface (snake_case by
    // core convention), which PSR-1's camel-caps rule intentionally exempts.
    // phpcs:disable PSR1.Methods.CamelCapsMethodName

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $this->prepared[] = [$query, array_values($args)];

        return 'PREPARED(' . count($this->prepared) . ')';
    }

    public function query(string $query): int|false
    {
        $this->queries[] = $query;

        return $this->queryResult;
    }

    public function get_var(string $query, int $x = 0, int $y = 0): mixed
    {
        $this->queries[] = $query;

        return $this->varResult;
    }

    /**
     * @return object|array<string, mixed>|null
     */
    public function get_row(string $query, mixed $output = 'OBJECT', int $y = 0): object|array|null
    {
        $this->queries[] = $query;
        $this->lastRowOutput = $output;

        return $this->rowResult;
    }

    /**
     * Records the compare-and-set UPDATE the option gateway dispatches and
     * yields the scripted affected-row count.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     * @param list<string> $format
     * @param list<string> $where_format
     */
    public function update(
        string $table,
        array $data,
        array $where,
        array $format = [],
        array $where_format = []
    ): int|false {
        $this->updates[] = [$table, $data, $where, $format, $where_format];

        return $this->queryResult;
    }

    // phpcs:enable PSR1.Methods.CamelCapsMethodName
}
