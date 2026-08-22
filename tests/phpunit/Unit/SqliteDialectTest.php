<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Storage\PdoConnection;
use LogLens\Storage\SqliteDialect;
use LogLens\Tests\TestCase;

/**
 * C-1: the SQLite dialect emits the SQL the engine expects, and the default
 * Connection exposes it. Postgres/MySQL dialects will get parallel tests.
 */
final class SqliteDialectTest extends TestCase
{
    public function testNow(): void
    {
        $dialect = new SqliteDialect();
        self::assertSame("datetime('now')", $dialect->now());
        self::assertSame("datetime('now','-7200 seconds')", $dialect->now(-7200));
        self::assertSame("datetime('now','+60 seconds')", $dialect->now(60));
    }

    public function testInsertIgnoreUsesTheOrIgnorePrefix(): void
    {
        $dialect = new SqliteDialect();
        self::assertSame(
            'INSERT OR IGNORE INTO occurrences (a,b) VALUES (?,?)',
            $dialect->insertIgnore('occurrences', 'a,b', '?,?'),
        );
    }

    public function testUpsertUsesOnConflictExcluded(): void
    {
        $dialect = new SqliteDialect();
        self::assertSame(
            'INSERT INTO error_groups (fingerprint,title) VALUES (?,?) ON CONFLICT(fingerprint) DO UPDATE SET title=excluded.title',
            $dialect->upsert('error_groups', 'fingerprint,title', '?,?', ['fingerprint'], ['title']),
        );
    }

    public function testDefaultConnectionExposesTheSqliteDialect(): void
    {
        $connection = $this->makeDatabase()->connection();
        self::assertInstanceOf(PdoConnection::class, $connection);
        self::assertSame('sqlite', $connection->dialect()->name());
    }

    public function testUpsertRoundTripsThroughARealDatabase(): void
    {
        // Proves the generated SQL actually executes (the persist() path relies on it).
        $pdo = $this->makeDatabase()->pdo;
        $dialect = new SqliteDialect();
        $pdo->exec('CREATE TABLE t (fp TEXT PRIMARY KEY, n INTEGER)');
        $sql = $dialect->upsert('t', 'fp,n', '?,?', ['fp'], ['n']);
        $pdo->prepare($sql)->execute(['x', 1]);
        $pdo->prepare($sql)->execute(['x', 2]);
        self::assertSame(2, (int) $pdo->query("SELECT n FROM t WHERE fp='x'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }
}
