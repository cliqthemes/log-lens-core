<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Config;
use LogLens\Database;
use LogLens\Tests\TestCase;
use RuntimeException;

/**
 * Tenancy is per application on every engine, and on the two engines where
 * that means a schema/database name it may never be guessed.
 *
 * The path-derived fallback exists for SQLite, where the tenant is unused —
 * the file path *is* the tenancy. On Postgres/MySQL the same fallback is a
 * silent data-partitioning bug: every application created through the UI gets
 * the same `log-lens.sqlite` file name, so a caller that forgot the id would
 * route all of them into one shared schema, invisible to the dashboard (which
 * passes the id). Found live on Postgres — the four CLI entry points and the
 * Laravel reporter/commands all omitted it.
 */
final class DatabaseTenancyTest extends TestCase
{
    public function testSqliteStillAcceptsAPathDerivedTenant(): void
    {
        $database = new Database($this->path('anything.sqlite'));
        self::assertNotNull($database->pdo);
    }

    public function testPostgresRefusesToGuessTheTenant(): void
    {
        Config::load(['database' => ['driver' => 'pgsql']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A tenant (application id) is required on the pgsql driver');
        new Database('/tmp/log-lens.sqlite');
    }

    public function testMysqlRefusesToGuessTheTenant(): void
    {
        Config::load(['database' => ['driver' => 'mysql']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A tenant (application id) is required on the mysql driver');
        new Database('/tmp/log-lens.sqlite');
    }
}
