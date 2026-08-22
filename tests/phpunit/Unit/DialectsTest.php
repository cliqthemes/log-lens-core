<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Config;
use LogLens\Storage\Dialects;
use LogLens\Storage\MysqlDialect;
use LogLens\Storage\PostgresDialect;
use LogLens\Storage\SqliteDialect;
use LogLens\Tests\TestCase;

/**
 * C-1: the three dialects emit engine-correct SQL, and Dialects::active()
 * resolves from config (SQLite by default — opt-in, not enforced).
 */
final class DialectsTest extends TestCase
{
    public function testNowPerEngine(): void
    {
        self::assertSame("datetime('now','-3600 seconds')", (new SqliteDialect())->now(-3600));
        self::assertSame(
            "to_char((NOW() + make_interval(secs => -3600)) AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS')",
            (new PostgresDialect())->now(-3600),
        );
        self::assertSame('(NOW() + INTERVAL -3600 SECOND)', (new MysqlDialect())->now(-3600));
    }

    /**
     * The day-window shift has no shared spelling: SQLite's modifier overload,
     * a Postgres interval on a cast date, and MySQL's DATE_ADD. Live-verified
     * against all three engines — each returns the same YYYY-MM-DD text.
     */
    public function testShiftDaysPerEngine(): void
    {
        self::assertSame("date(d,'-29 days')", (new SqliteDialect())->shiftDays('d', -29));
        self::assertSame(
            "to_char((d)::date + make_interval(days => -29), 'YYYY-MM-DD')",
            (new PostgresDialect())->shiftDays('d', -29),
        );
        self::assertSame(
            "DATE_FORMAT(DATE_ADD(d, INTERVAL -29 DAY), '%Y-%m-%d')",
            (new MysqlDialect())->shiftDays('d', -29),
        );
        self::assertSame("date(d,'+1 days')", (new SqliteDialect())->shiftDays('d', 1));
    }

    public function testInsertIgnorePerEngine(): void
    {
        self::assertSame('INSERT OR IGNORE INTO t (a,b) VALUES (?,?)', (new SqliteDialect())->insertIgnore('t', 'a,b', '?,?'));
        self::assertSame('INSERT INTO t (a,b) VALUES (?,?) ON CONFLICT DO NOTHING', (new PostgresDialect())->insertIgnore('t', 'a,b', '?,?'));
        self::assertSame('INSERT IGNORE INTO t (a,b) VALUES (?,?)', (new MysqlDialect())->insertIgnore('t', 'a,b', '?,?'));
    }

    public function testInsertIgnoreSelectPerEngine(): void
    {
        self::assertSame('INSERT OR IGNORE INTO t (a) SELECT x FROM y', (new SqliteDialect())->insertIgnoreSelect('t', 'a', 'SELECT x FROM y'));
        self::assertSame('INSERT INTO t (a) SELECT x FROM y ON CONFLICT DO NOTHING', (new PostgresDialect())->insertIgnoreSelect('t', 'a', 'SELECT x FROM y'));
        self::assertSame('INSERT IGNORE INTO t (a) SELECT x FROM y', (new MysqlDialect())->insertIgnoreSelect('t', 'a', 'SELECT x FROM y'));
    }

    public function testUpsertConflictVsDuplicateKey(): void
    {
        $args = ['app_settings', 'key,value', '?,?', ['key'], ['value', 'updated_at=CURRENT_TIMESTAMP']];
        self::assertSame(
            'INSERT INTO app_settings (key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=CURRENT_TIMESTAMP',
            (new PostgresDialect())->upsert(...$args),
        );
        self::assertSame(
            'INSERT INTO app_settings (key,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value), updated_at=CURRENT_TIMESTAMP',
            (new MysqlDialect())->upsert(...$args),
        );
    }

    public function testCaseInsensitiveLikePerEngine(): void
    {
        self::assertSame('name LIKE ?', (new SqliteDialect())->caseInsensitiveLike('name'));
        self::assertSame('name ILIKE ?', (new PostgresDialect())->caseInsensitiveLike('name'));
        self::assertSame('name LIKE ?', (new MysqlDialect())->caseInsensitiveLike('name'));
    }

    public function testCaseInsensitiveEqualsPerEngine(): void
    {
        self::assertSame('m.slug=?', (new SqliteDialect())->caseInsensitiveEquals('m.slug'));
        self::assertSame('LOWER(m.slug)=LOWER(?)', (new PostgresDialect())->caseInsensitiveEquals('m.slug'));
        self::assertSame('m.slug=?', (new MysqlDialect())->caseInsensitiveEquals('m.slug'));
    }

    public function testCaseInsensitiveOrderPerEngine(): void
    {
        self::assertSame('m.name', (new SqliteDialect())->caseInsensitiveOrder('m.name'));
        self::assertSame('LOWER(m.name)', (new PostgresDialect())->caseInsensitiveOrder('m.name'));
        self::assertSame('m.name', (new MysqlDialect())->caseInsensitiveOrder('m.name'));
    }

    public function testCastToTextPerEngine(): void
    {
        self::assertSame('CAST(m.id AS TEXT)', (new SqliteDialect())->castToText('m.id'));
        self::assertSame('CAST(m.id AS TEXT)', (new PostgresDialect())->castToText('m.id'));
        self::assertSame('CAST(m.id AS CHAR)', (new MysqlDialect())->castToText('m.id'));
    }

    public function testJsonObjectAndArrayAggPerEngine(): void
    {
        $pairs = ['id' => 't.id', 'name' => 't.name'];
        self::assertSame("json_object('id',t.id,'name',t.name)", (new SqliteDialect())->jsonObject($pairs));
        self::assertSame("json_build_object('id',t.id,'name',t.name)", (new PostgresDialect())->jsonObject($pairs));
        self::assertSame("json_object('id',t.id,'name',t.name)", (new MysqlDialect())->jsonObject($pairs));

        self::assertSame('json_group_array(x)', (new SqliteDialect())->jsonArrayAgg('x'));
        self::assertSame('json_agg(x)', (new PostgresDialect())->jsonArrayAgg('x'));
        self::assertSame('JSON_ARRAYAGG(x)', (new MysqlDialect())->jsonArrayAgg('x'));
    }

    public function testQuoteIdentifierPerEngine(): void
    {
        self::assertSame('"release"', (new SqliteDialect())->quoteIdentifier('release'));
        self::assertSame('"release"', (new PostgresDialect())->quoteIdentifier('release'));
        self::assertSame('`release`', (new MysqlDialect())->quoteIdentifier('release'));
    }

    public function testActiveResolvesFromConfigAndDefaultsToSqlite(): void
    {
        Config::load(['database' => ['driver' => 'sqlite']]);
        Dialects::reset();
        self::assertInstanceOf(SqliteDialect::class, Dialects::active());

        Config::load(['database' => ['driver' => 'pgsql']]);
        Dialects::reset();
        self::assertInstanceOf(PostgresDialect::class, Dialects::active());

        Config::load(['database' => ['driver' => 'mysql']]);
        Dialects::reset();
        self::assertInstanceOf(MysqlDialect::class, Dialects::active());

        Config::load([]); // no driver configured
        Dialects::reset();
        self::assertSame('sqlite', Dialects::active()->name(), 'Defaults to SQLite — opt-in, not enforced.');
    }
}
