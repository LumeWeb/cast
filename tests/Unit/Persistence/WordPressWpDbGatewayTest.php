<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Persistence;

use LumeWeb\Cast\Persistence\WpDbGateway;
use LumeWeb\Cast\Persistence\WordPressWpDbGateway;
use PHPUnit\Framework\TestCase;

/**
 * The WordPress `$wpdb` adapter for {@see WpDbGateway}.
 *
 * Bound parameters are always routed through wpdb->prepare() so values are
 * escaped exactly the way WordPress does; identifer-free raw statements pass
 * through untouched. query() normalises wpdb's int|false to int, and getRow()
 * always asks for ARRAY_A so {@see SqlWorkItemRepository} receives assoc rows.
 */
final class WordPressWpDbGatewayTest extends TestCase
{
    private FakeWpDb $db;

    private WordPressWpDbGateway $gateway;

    protected function setUp(): void
    {
        $this->db = new FakeWpDb();
        $this->gateway = new WordPressWpDbGateway($this->db);
    }

    public function testImplementsTheGatewaySeam(): void
    {
        self::assertInstanceOf(WpDbGateway::class, $this->gateway);
    }

    public function testQueryWithoutParamsDispatchesTheRawSql(): void
    {
        $this->db->queryResult = 3;

        self::assertSame(3, $this->gateway->query('UPDATE t SET status = %s', []));

        self::assertSame([], $this->db->prepared, 'no params means no prepare() round-trip');
        self::assertSame(['UPDATE t SET status = %s'], $this->db->queries);
    }

    public function testQueryPreparesBoundParamsPositionally(): void
    {
        $this->db->queryResult = 1;

        self::assertSame(1, $this->gateway->query(
            'UPDATE t SET status = %s WHERE url_hash = %s AND retry_at <= %d',
            ['done', 'abc', 5],
        ));

        self::assertSame(
            [['UPDATE t SET status = %s WHERE url_hash = %s AND retry_at <= %d', ['done', 'abc', 5]]],
            $this->db->prepared,
        );
        self::assertSame(['PREPARED(1)'], $this->db->queries);
    }

    public function testQueryNormalisesWpdbFalseToZero(): void
    {
        $this->db->queryResult = false;

        self::assertSame(0, $this->gateway->query('UPDATE t SET status = %s', ['done']));
    }

    public function testGetVarDelegatesTheRawSqlWithoutParams(): void
    {
        $this->db->varResult = 12;

        self::assertSame(12, $this->gateway->getVar('SELECT COUNT(*) FROM t'));

        self::assertSame(['SELECT COUNT(*) FROM t'], $this->db->queries);
        self::assertSame([], $this->db->prepared);
    }

    public function testGetVarPreparesBoundParamsThenDelegates(): void
    {
        $this->db->varResult = 7;

        self::assertSame(7, $this->gateway->getVar('SELECT priority FROM t WHERE url_hash = %s', ['abc']));

        self::assertSame([['SELECT priority FROM t WHERE url_hash = %s', ['abc']]], $this->db->prepared);
        self::assertSame(['PREPARED(1)'], $this->db->queries);
    }

    public function testGetVarReturnsNullWhenNoRow(): void
    {
        $this->db->varResult = null;

        self::assertNull($this->gateway->getVar('SELECT priority FROM t WHERE url_hash = %s', ['missing']));
    }

    public function testGetRowRequestsAnAssociativeRow(): void
    {
        $this->db->rowResult = ['url_hash' => 'abc', 'kind' => 'page'];

        self::assertSame(
            ['url_hash' => 'abc', 'kind' => 'page'],
            $this->gateway->getRow('SELECT url_hash, kind FROM t WHERE url_hash = %s', ['abc']),
        );
        self::assertSame('ARRAY_A', $this->db->lastRowOutput);
    }

    public function testGetRowConvertsAnObjectRowToArray(): void
    {
        $this->db->rowResult = (object) ['url_hash' => 'abc', 'kind' => 'page'];

        self::assertSame(
            ['url_hash' => 'abc', 'kind' => 'page'],
            $this->gateway->getRow('SELECT url_hash, kind FROM t'),
        );
    }

    public function testGetRowReturnsNullWhenNoRow(): void
    {
        $this->db->rowResult = null;

        self::assertNull($this->gateway->getRow('SELECT url_hash FROM t WHERE url_hash = %s', ['missing']));
    }
}
