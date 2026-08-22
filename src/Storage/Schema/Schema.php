<?php
declare(strict_types=1);

namespace LogLens\Storage\Schema;

use PDO;

/**
 * The migration-facing facade: builds a {@see Blueprint} from a migration's
 * callback, compiles it through the active {@see Grammar}, and executes the
 * resulting statements — Log Lens's answer to Laravel's `Schema` facade.
 */
final class Schema
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Grammar $grammar,
    ) {
    }

    /** Define and create a brand-new table. */
    public function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        $this->run($this->grammar->compileCreate($blueprint));
    }

    /**
     * Add columns and/or indexes to an existing table (an `up()` migration),
     * or remove columns/indexes from one (a `down()` migration that called
     * `$table->dropColumn(...)`/`dropIndex(...)`) — whichever the callback
     * declared. A drop always compiles indexes before columns: the engine
     * rejects dropping a column an index still references.
     */
    public function table(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        $isDrop = $blueprint->dropColumns !== [] || $blueprint->dropIndexes !== [];
        $statements = $isDrop
            ? [...$this->grammar->compileDropIndexes($blueprint), ...$this->grammar->compileDropColumns($blueprint)]
            : $this->grammar->compileAddColumns($blueprint); // handles an indexes-only Blueprint fine too (no columns to add)
        $this->run($statements);
    }

    public function dropIfExists(string $table): void
    {
        $this->pdo->exec($this->grammar->compileDropTableIfExists($table));
    }

    /** @param list<string> $statements */
    private function run(array $statements): void
    {
        foreach ($statements as $statement) {
            $this->pdo->exec($statement);
        }
    }
}
