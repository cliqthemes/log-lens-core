<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Storage\UniqueViolation;
use LogLens\Tests\TestCase;
use PDOException;

/**
 * Duplicate-key detection across the three engines' very different wording —
 * the message-only test this replaces matched SQLite and Postgres and missed
 * MySQL, turning a 422 ("already exists") into a raw 500.
 */
final class UniqueViolationTest extends TestCase
{
    public function testRecognisesEachEnginesDuplicateKeyError(): void
    {
        self::assertTrue(UniqueViolation::matches(self::error(
            '23000',
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: modules.name',
        )), 'sqlite');
        self::assertTrue(UniqueViolation::matches(self::error(
            '23505',
            'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "modules_name_key"',
        )), 'pgsql');
        self::assertTrue(UniqueViolation::matches(self::error(
            '23000',
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'Billing' for key 'modules.name'",
        )), 'mysql');
    }

    public function testDoesNotClaimOtherIntegrityFailures(): void
    {
        self::assertFalse(UniqueViolation::matches(self::error(
            '23503',
            'SQLSTATE[23503]: Foreign key violation: 7 ERROR:  update or delete on table "modules" violates foreign key constraint',
        )));
        self::assertFalse(UniqueViolation::matches(self::error(
            '23000',
            'SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails',
        )));
        self::assertFalse(UniqueViolation::matches(self::error('HY000', 'SQLSTATE[HY000]: General error: 5 database is locked')));
    }

    /** A PDOException carrying a given SQLSTATE — the constructor only takes an int code. */
    private static function error(string $state, string $message): PDOException
    {
        return new class($state, $message) extends PDOException {
            public function __construct(string $state, string $message)
            {
                parent::__construct($message);
                $this->code = $state;
            }
        };
    }
}
