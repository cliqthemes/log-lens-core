<?php
declare(strict_types=1);

namespace LogLens\Storage;

use PDOException;

/**
 * Whether a PDO error is a duplicate-key rejection, on any supported engine.
 *
 * The three engines word this completely differently — SQLite says "UNIQUE
 * constraint failed", Postgres "duplicate key value violates unique
 * constraint", MySQL "Duplicate entry 'x' for key 'modules.name'" — so the
 * message test every caller used to do ("contains 'unique'") quietly matched
 * two engines out of three and let MySQL's version escape as a raw 500 where
 * the API owes the caller a 422 (found live: creating a duplicate module,
 * tag, connector, or release).
 *
 * SQLSTATE first, because that is the part that is standardised: Postgres has
 * a code specific to this (23505), and the other two report the whole
 * integrity-violation class (23000) — which also covers foreign-key and
 * not-null failures, so those two still need the message to tell a duplicate
 * from a different kind of constraint. Both halves together, rather than
 * either alone.
 */
final class UniqueViolation
{
    public static function matches(PDOException $exception): bool
    {
        $state = (string) $exception->getCode();
        if ($state === '23505') {
            return true;
        }
        if ($state !== '23000') {
            return false;
        }
        $message = strtolower($exception->getMessage());
        return str_contains($message, 'unique') || str_contains($message, 'duplicate');
    }
}
