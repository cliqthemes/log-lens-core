<?php
declare(strict_types=1);

namespace LogLens\Storage;

use PDO;

/**
 * The one {@see Connection} implementation: a plain PDO handle plus the typed
 * helpers and a re-entrant transaction wrapper. Engine-agnostic — SQLite,
 * Postgres, and MySQL all speak PDO the same way here; what differs (SQL
 * fragments) is delegated entirely to the injected {@see Dialect} (C-1).
 */
final class PdoConnection implements Connection
{
    private int $transactionDepth = 0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Dialect $dialect = new SqliteDialect(),
    ) {
    }

    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    public function selectOne(string $sql, array $params = []): ?array
    {
        $statement = $this->run($sql, $params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function selectAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function selectValue(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $work): mixed
    {
        // Re-entrant: an inner transaction() joins the outer one so callers can
        // compose without worrying about "already in a transaction" errors.
        if ($this->transactionDepth > 0) {
            $this->transactionDepth++;
            try {
                $result = $work($this);
            } catch (\Throwable $exception) {
                $this->transactionDepth--;
                throw $exception;
            }
            $this->transactionDepth--;
            return $result;
        }

        $this->pdo->beginTransaction();
        $this->transactionDepth = 1;
        try {
            $result = $work($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        } finally {
            $this->transactionDepth = 0;
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<int|string,mixed> $params */
    private function run(string $sql, array $params): \PDOStatement
    {
        if ($params === []) {
            $statement = $this->pdo->query($sql);
            if ($statement === false) {
                throw new \RuntimeException('Query failed: ' . $sql);
            }
            return $statement;
        }
        $statement = $this->pdo->prepare($sql);
        // Bind by PHP type instead of handing the whole array to execute(),
        // which types every value as a string. Harmless in a WHERE clause on
        // any engine, fatal in `LIMIT ?`: MySQL emulates prepares by default,
        // so a string-typed bind is interpolated quoted and `LIMIT '200'` is a
        // syntax error — which took out issue listing, sources, occurrence
        // paging, and every other paged query on MySQL. Fixing it at the one
        // seam every query goes through beats casting at each call site.
        foreach ($params as $key => $value) {
            $statement->bindValue(
                is_int($key) ? $key + 1 : $key,
                $value,
                match (true) {
                    $value === null => PDO::PARAM_NULL,
                    is_bool($value) => PDO::PARAM_BOOL,
                    is_int($value) => PDO::PARAM_INT,
                    default => PDO::PARAM_STR,
                },
            );
        }
        $statement->execute();
        return $statement;
    }
}
