<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Persistence;

/**
 * WordPress `$wpdb` implementation of the {@see WpDbGateway} adapter.
 *
 * Bound parameters are always routed through `$wpdb->prepare()` so values are
 * escaped exactly the way WordPress does; identifier-free raw statements pass
 * through untouched. query() normalises wpdb's `int|false` to int, and
 * getRow() always asks for ARRAY_A so the SQL repository receives assoc rows.
 *
 * The `$wpdb` global is the default database handle; an alternative object (a
 * test double) can be injected so the adapter stays unit-testable without a
 * live database. The handle is stored as a plain object (so any double can be
 * injected at runtime) and narrowed to the real {@see \wpdb} surface for
 * static analysis through {@see WordPressWpDbGateway::db()}.
 */
final class WordPressWpDbGateway implements WpDbGateway
{
    /**
     * The live database handle (the `$wpdb` global or an injected double).
     */
    private readonly object $db;

    public function __construct(?object $db = null)
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if (!is_object($wpdb)) {
            throw new \RuntimeException('WordPressWpDbGateway requires the WordPress $wpdb global.');
        }

        $this->db = $db ?? $wpdb;
    }

    /**
     * The live handle, narrowed to the wpdb surface Cast talks to.
     *
     * No native return type: the injected handle is only ever a plain object
     * (test doubles are never real `wpdb` instances), so the wpdb narrowing is
     * purely a static-analysis contract and must not be enforced at runtime.
     *
     * @return \wpdb
     */
    private function db()
    {
        /** @var \wpdb $db */
        $db = $this->db;

        return $db;
    }

    /**
     * @param list<string|int> $params
     */
    public function query(string $sql, array $params = []): int
    {
        $result = $this->db()->query($this->prepare($sql, $params));

        return is_int($result) ? $result : 0;
    }

    /**
     * @param list<string|int> $params
     */
    public function getVar(string $sql, array $params = []): mixed
    {
        return $this->db()->get_var($this->prepare($sql, $params));
    }

    /**
     * @param list<string|int> $params
     * @return array<string, mixed>|null
     */
    public function getRow(string $sql, array $params = []): ?array
    {
        $row = $this->db()->get_row($this->prepare($sql, $params), 'ARRAY_A');

        if ($row === null) {
            return null;
        }

        if (is_object($row)) {
            return (array) $row;
        }

        return is_array($row) ? $row : null;
    }

    /**
     * Bind positional parameters through wpdb->prepare() unless none are set.
     *
     * @param list<string|int> $params
     */
    private function prepare(string $sql, array $params): string
    {
        if ($params === []) {
            return $sql;
        }

        // @phpstan-ignore argument.type (wpdb stub narrows prepare()'s query arg to literal-string)
        $prepared = $this->db()->prepare($sql, ...$params);

        return is_string($prepared) ? $prepared : $sql;
    }
}
