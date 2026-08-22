<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Storage\Connection;
use LogLens\Tests\TestCase;
use RuntimeException;

/**
 * C-1: the SqliteConnection storage seam. Uses the `modules` table from the
 * migrated schema as a convenient real target.
 */
final class SqliteConnectionTest extends TestCase
{
    private function connection(): Connection
    {
        return $this->makeDatabase()->connection();
    }

    public function testInsertReturnsIdAndSelectOneReadsItBack(): void
    {
        $db = $this->connection();
        $id = $db->insert('INSERT INTO modules(name,slug,color) VALUES(?,?,?)', ['Billing', 'billing', '#7c3aed']);
        self::assertGreaterThan(0, $id);

        $row = $db->selectOne('SELECT name,slug FROM modules WHERE id=?', [$id]);
        self::assertSame('Billing', $row['name']);
        self::assertSame('billing', $row['slug']);
    }

    public function testSelectValueReturnsScalarAndNullWhenAbsent(): void
    {
        $db = $this->connection();
        self::assertSame(0, (int) $db->selectValue('SELECT COUNT(*) FROM modules'));
        self::assertNull($db->selectValue('SELECT id FROM modules WHERE slug=?', ['nope']));
    }

    public function testExecuteReturnsAffectedRowCount(): void
    {
        $db = $this->connection();
        $db->insert('INSERT INTO modules(name,slug,color) VALUES(?,?,?)', ['A', 'a', '#111111']);
        $affected = $db->execute('UPDATE modules SET color=? WHERE slug=?', ['#222222', 'a']);
        self::assertSame(1, $affected);
    }

    public function testTransactionCommitsOnSuccess(): void
    {
        $db = $this->connection();
        $db->transaction(function (Connection $tx): void {
            $tx->insert('INSERT INTO modules(name,slug,color) VALUES(?,?,?)', ['C', 'c', '#333333']);
        });
        self::assertSame(1, (int) $db->selectValue('SELECT COUNT(*) FROM modules WHERE slug=?', ['c']));
    }

    public function testTransactionRollsBackAndRethrowsOnFailure(): void
    {
        $db = $this->connection();
        try {
            $db->transaction(function (Connection $tx): void {
                $tx->insert('INSERT INTO modules(name,slug,color) VALUES(?,?,?)', ['D', 'd', '#444444']);
                throw new RuntimeException('boom');
            });
            self::fail('Expected the throwable to propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }
        self::assertSame(0, (int) $db->selectValue('SELECT COUNT(*) FROM modules WHERE slug=?', ['d']));
    }

    public function testNestedTransactionJoinsOuterAndRollsBackTogether(): void
    {
        $db = $this->connection();
        try {
            $db->transaction(function (Connection $tx): void {
                $tx->insert('INSERT INTO modules(name,slug,color) VALUES(?,?,?)', ['Outer', 'outer', '#555555']);
                $tx->transaction(function (Connection $inner): void {
                    $inner->insert('INSERT INTO modules(name,slug,color) VALUES(?,?,?)', ['Inner', 'inner', '#666666']);
                });
                throw new RuntimeException('rollback everything');
            });
        } catch (RuntimeException) {
            // expected
        }
        // The inner write joined the outer transaction, so both are rolled back.
        self::assertSame(0, (int) $db->selectValue('SELECT COUNT(*) FROM modules'));
    }
}
