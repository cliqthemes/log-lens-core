<?php
declare(strict_types=1);

namespace LogLens\Storage;

use PDO;

/**
 * The storage seam (C-1).
 *
 * Everything the app does to the database is expressed through this small
 * interface rather than talking to PDO directly, so raw SQL stops spreading and
 * there is one place to evolve (a second driver, query logging, metrics) later.
 * It is deliberately thin — parameterized statements plus a transaction helper —
 * and keeps SQLite as the only implementation today (local-first).
 *
 * `pdo()` is a temporary escape hatch for the byte-range streaming reads and a
 * few complex statements that have not been migrated yet; new code should use
 * the typed helpers.
 */
interface Connection
{
    /**
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null The first row, or null when none match.
     */
    public function selectOne(string $sql, array $params = []): ?array;

    /**
     * @param array<int|string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function selectAll(string $sql, array $params = []): array;

    /**
     * The first column of the first row (e.g. a COUNT or a single id), or null.
     *
     * @param array<int|string,mixed> $params
     */
    public function selectValue(string $sql, array $params = []): mixed;

    /**
     * Run an INSERT/UPDATE/DELETE and return the number of affected rows.
     *
     * @param array<int|string,mixed> $params
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * Run an INSERT and return the new row id.
     *
     * @param array<int|string,mixed> $params
     */
    public function insert(string $sql, array $params = []): int;

    /**
     * Run $work inside a transaction, committing on success and rolling back on
     * any throwable (which is re-thrown). Nested calls join the outer
     * transaction rather than starting a new one.
     *
     * @template T
     * @param callable(Connection):T $work
     * @return T
     */
    public function transaction(callable $work): mixed;

    /** Escape hatch for statements not yet expressible through this seam. */
    public function pdo(): PDO;

    /** The SQL dialect for the engine behind this connection (C-1). */
    public function dialect(): Dialect;
}
