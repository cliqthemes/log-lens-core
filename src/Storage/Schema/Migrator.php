<?php
declare(strict_types=1);

namespace LogLens\Storage\Schema;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Discovers migration files, tracks which have run in a `migrations` table,
 * and applies pending ones — Log Lens's answer to Laravel's migrator,
 * shared by all three engines (each {@see \LogLens\Storage\Driver}
 * supplies its own {@see Grammar} and locking statement).
 *
 * **Legacy-install bridge:** every SQLite database created before this
 * system existed already has the full schema (built by the old inline-SQL
 * `runMigration()`), but no `migrations` table. The first connection under
 * the new system detects this — no `migrations` table yet, but a core table
 * (`modules`) already does exist — and records migrations *up to
 * $legacyBoundary* as already applied (batch 0) *without* executing them,
 * since that's the schema state the old system actually produced. Anything
 * *after* the boundary is a genuinely new migration added since the
 * Migrator replaced the old system, and must run for real even on a legacy
 * install — bridging it too would silently skip a real schema change (this
 * bit us once: a legacy database whose first-ever connect under the new
 * system happened after a later migration already existed on disk had that
 * migration wrongly marked "already applied" without its columns ever being
 * created). Postgres/MySQL have no such installs (they never shipped before
 * the Migrator), so this only ever applies to SQLite.
 */
final class Migrator
{
    public function __construct(private readonly string $migrationsPath)
    {
    }

    /**
     * @param string $beginStatement the statement that opens the migration
     *   transaction — SQLite needs `BEGIN IMMEDIATE` specifically (takes the
     *   write lock up front, so busy_timeout makes a concurrent worker wait
     *   instead of racing on the first ALTER TABLE); `BEGIN` elsewhere.
     * @param string|null $legacyMarkerTable a table that only exists on an
     *   install that predates the Migrator (SQLite's `modules`); null for
     *   engines with no such installs (Postgres, MySQL).
     * @param string|null $legacyBoundary the filename (no `.php`) of the last
     *   migration that describes the schema the old inline system actually
     *   produced — every migration up to and including it is bridged
     *   (assumed already applied); every migration after it always runs for
     *   real, even on a legacy install. Ignored when $legacyMarkerTable is
     *   null. Migrations sort by filename, so this is a simple boundary.
     */
    public function migrate(
        PDO $pdo,
        Grammar $grammar,
        string $beginStatement = 'BEGIN',
        ?string $legacyMarkerTable = null,
        ?string $legacyBoundary = null,
    ): void {
        $schema = new Schema($pdo, $grammar);
        $migrationsTableExisted = $this->tableExists($pdo, $grammar, 'migrations');
        if (!$migrationsTableExisted) {
            $schema->create('migrations', function (Blueprint $table): void {
                $table->id();
                $table->string('migration', 191)->unique();
                $table->integer('batch');
                $table->timestamp('run_at')->useCurrent();
            });
        }

        $legacyInstall = !$migrationsTableExisted
            && $legacyMarkerTable !== null
            && $this->tableExists($pdo, $grammar, $legacyMarkerTable);

        $files = $this->discoverMigrationFiles();
        if ($this->pendingMigrations($pdo, $files) === []) {
            return;
        }

        // Serialize concurrent workers: re-check the pending list once inside
        // the lock, in case another worker just finished while this one was
        // opening the transaction.
        $pdo->exec($beginStatement);
        try {
            $pending = $this->pendingMigrations($pdo, $files);
            if ($pending === []) {
                $pdo->exec('COMMIT');
                return;
            }
            $record = $pdo->prepare('INSERT INTO migrations(migration,batch) VALUES (?,?)');

            if ($legacyInstall) {
                // $pending is filename-sorted (see pendingMigrations()), so
                // everything up to the boundary comes first.
                foreach ($pending as $name) {
                    if ($legacyBoundary !== null && strcmp($name, $legacyBoundary) > 0) {
                        break;
                    }
                    $record->execute([$name, 0]);
                }
                $pending = $this->pendingMigrations($pdo, $files); // excludes what was just bridged
            }

            if ($pending !== []) {
                $batch = $this->nextBatch($pdo);
                foreach ($pending as $name) {
                    $files[$name]->up($schema);
                    $record->execute([$name, $batch]);
                }
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $exception) {
            $pdo->exec('ROLLBACK');
            throw $exception;
        }
    }

    /** Roll back the last $steps batch(es), running each migration's down() in reverse order. Returns how many migrations were rolled back. */
    public function rollback(PDO $pdo, Grammar $grammar, int $steps = 1): int
    {
        $schema = new Schema($pdo, $grammar);
        $batches = $this->batchesToRollback($pdo, $steps);
        if ($batches === []) {
            return 0;
        }
        $files = $this->discoverMigrationFiles();
        $placeholders = implode(',', array_fill(0, count($batches), '?'));
        $rows = $pdo->prepare("SELECT migration FROM migrations WHERE batch IN ({$placeholders}) ORDER BY id DESC");
        $rows->execute($batches);
        $names = $rows->fetchAll(PDO::FETCH_COLUMN);

        $delete = $pdo->prepare('DELETE FROM migrations WHERE migration=?');
        $rolledBack = 0;
        foreach ($names as $name) {
            if (isset($files[$name])) {
                $files[$name]->down($schema);
            }
            $delete->execute([$name]);
            $rolledBack++;
        }
        return $rolledBack;
    }

    /** @param array<string,Migration> $files @return list<string> */
    private function pendingMigrations(PDO $pdo, array $files): array
    {
        $ran = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_diff(array_keys($files), $ran));
    }

    /** @return array<string,Migration> every migration file, keyed by filename (without .php), sorted so numeric prefixes run in order. */
    private function discoverMigrationFiles(): array
    {
        $migrations = [];
        foreach (glob($this->migrationsPath . '/*.php') ?: [] as $path) {
            $name = basename($path, '.php');
            $migration = require $path;
            if (!$migration instanceof Migration) {
                throw new RuntimeException("Migration file {$path} must return a Migration instance.");
            }
            $migrations[$name] = $migration;
        }
        ksort($migrations);
        return $migrations;
    }

    private function nextBatch(PDO $pdo): int
    {
        return 1 + (int) $pdo->query('SELECT COALESCE(MAX(batch),0) FROM migrations')->fetchColumn();
    }

    /** @return list<int> the batch numbers to roll back, highest first. */
    private function batchesToRollback(PDO $pdo, int $steps): array
    {
        $statement = $pdo->prepare('SELECT DISTINCT batch FROM migrations ORDER BY batch DESC LIMIT ?');
        $statement->bindValue(1, max(1, $steps), PDO::PARAM_INT);
        $statement->execute();
        return array_map(intval(...), $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function tableExists(PDO $pdo, Grammar $grammar, string $table): bool
    {
        $sql = match ($grammar->name()) {
            'sqlite' => "SELECT 1 FROM sqlite_master WHERE type='table' AND name=?",
            'pgsql' => 'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name=?',
            'mysql' => 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name=?',
            default => throw new RuntimeException("Unknown engine: {$grammar->name()}"),
        };
        $statement = $pdo->prepare($sql);
        $statement->execute([$table]);
        return (bool) $statement->fetchColumn();
    }
}
