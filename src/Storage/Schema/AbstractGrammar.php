<?php
declare(strict_types=1);

namespace LogLens\Storage\Schema;

/**
 * Shared Grammar behaviour: assembling CREATE TABLE / ADD COLUMN / indexes
 * from a Blueprint is identical across engines once each engine supplies its
 * own single-column rendering ({@see columnLine}) and a few small hooks
 * (table options, partial-index support, identifier quoting). That per-column
 * rendering is intentionally NOT shared — it is where SQLite/Postgres/MySQL
 * genuinely diverge (types, generated columns, case-insensitive uniqueness),
 * and forcing it through one template would trade three readable methods for
 * one with a dozen conditionals.
 */
abstract class AbstractGrammar implements Grammar
{
    abstract public function name(): string;

    /** The complete "name TYPE ... " fragment for one column (no trailing comma). */
    abstract protected function columnLine(ColumnDefinition $column): string;

    /** Extra statements a column needs beyond its inline definition (e.g. Postgres's functional lower() unique index). @return list<string> */
    abstract protected function extraStatementsForColumn(string $table, ColumnDefinition $column): array;

    /** Trailing CREATE TABLE clause (MySQL's `ENGINE=InnoDB DEFAULT CHARSET=...`); empty on SQLite/Postgres. */
    protected function tableOptions(): string
    {
        return '';
    }

    /** This engine's "now", formatted as this app's canonical timestamp string (not a native temporal value) — resolves `->useCurrent()`. */
    abstract protected function currentTimestamp(): string;

    /** Render a column's default (literal or {@see Expr}) as SQL. */
    protected function resolveDefault(ColumnDefinition $column): string
    {
        $default = $column->default;
        if ($default instanceof Expr) {
            return $default->sql === '__CURRENT_TIMESTAMP__' ? $this->currentTimestamp() : $default->sql;
        }
        if (is_string($default)) {
            return "'" . str_replace("'", "''", $default) . "'";
        }
        return (string) $default; // int|float
    }

    public function supportsPartialIndex(): bool
    {
        return true;
    }

    public function quoteIdentifier(string $name): string
    {
        return '"' . $name . '"';
    }

    public function compileCreate(Blueprint $blueprint): array
    {
        $lines = array_map(fn (ColumnDefinition $column): string => $this->columnLine($column), $blueprint->columns);
        foreach ($blueprint->indexes as $index) {
            if ($index->kind === 'primary') {
                $lines[] = 'PRIMARY KEY(' . implode(',', $this->quotedIndexColumns($index)) . ')';
            } elseif ($index->kind === 'unique') {
                $lines[] = 'UNIQUE(' . implode(',', $this->quotedIndexColumns($index)) . ')';
            }
        }
        foreach ($blueprint->foreignKeys as $foreignKey) {
            $lines[] = $this->foreignKeyClause($foreignKey);
        }

        $statements = [
            "CREATE TABLE IF NOT EXISTS {$blueprint->table} (\n    "
                . implode(",\n    ", $lines) . "\n){$this->tableOptions()};",
        ];
        foreach ($blueprint->columns as $column) {
            array_push($statements, ...$this->extraStatementsForColumn($blueprint->table, $column));
        }
        foreach ($blueprint->indexes as $index) {
            if ($index->kind === 'index') {
                $statements[] = $this->indexStatement($blueprint->table, $index);
            }
        }
        return $statements;
    }

    public function compileAddColumns(Blueprint $blueprint): array
    {
        $statements = [];
        foreach ($blueprint->columns as $column) {
            $statements[] = "ALTER TABLE {$blueprint->table} ADD COLUMN " . $this->columnLine($column) . ';';
            array_push($statements, ...$this->extraStatementsForColumn($blueprint->table, $column));
        }
        array_push($statements, ...$this->compileAddIndexes($blueprint));
        return $statements;
    }

    public function compileAddIndexes(Blueprint $blueprint): array
    {
        $statements = [];
        foreach ($blueprint->indexes as $index) {
            $statements[] = $index->kind === 'unique'
                ? $this->uniqueIndexStatement($blueprint->table, $index)
                : $this->indexStatement($blueprint->table, $index);
        }
        return $statements;
    }

    public function compileDropColumns(Blueprint $blueprint): array
    {
        return array_map(
            fn (string $name): string => "ALTER TABLE {$blueprint->table} DROP COLUMN {$this->quoteIdentifier($name)};",
            $blueprint->dropColumns,
        );
    }

    public function compileDropIndexes(Blueprint $blueprint): array
    {
        return array_map(fn (string $name): string => "DROP INDEX {$name};", $blueprint->dropIndexes);
    }

    public function compileDropTableIfExists(string $table): string
    {
        return "DROP TABLE IF EXISTS {$table};";
    }

    protected function foreignKeyClause(ForeignKeyDefinition $foreignKey): string
    {
        $action = strtoupper($foreignKey->onDeleteAction);
        return "FOREIGN KEY ({$foreignKey->column}) REFERENCES {$foreignKey->onTable}({$foreignKey->referencesColumn}) ON DELETE {$action}";
    }

    protected function indexStatement(string $table, IndexDefinition $index): string
    {
        $sql = "CREATE INDEX {$index->name} ON {$table}(" . implode(',', $this->quotedIndexColumns($index)) . ')';
        if ($index->where !== null && $this->supportsPartialIndex()) {
            $sql .= " WHERE {$index->where}";
        }
        return $sql . ';';
    }

    protected function uniqueIndexStatement(string $table, IndexDefinition $index): string
    {
        return "CREATE UNIQUE INDEX {$index->name} ON {$table}(" . implode(',', $this->quotedIndexColumns($index)) . ');';
    }

    /**
     * Quote each column of an index, tolerating a trailing ` DESC`/` ASC`
     * (`['last_seen DESC']` mixes an identifier with a sort-direction
     * keyword — only the identifier gets quoted) and applying any
     * MySQL-only prefix length declared for that column (see
     * {@see IndexDefinition::mysqlPrefix()} and {@see columnWithPrefix()}).
     *
     * @return list<string>
     */
    protected function quotedIndexColumns(IndexDefinition $index): array
    {
        return array_map(function (string $column) use ($index): string {
            [$identifier, $suffix] = preg_match('/^(\S+)(\s+(?:ASC|DESC))$/i', $column, $matches) === 1
                ? [$matches[1], $matches[2]]
                : [$column, ''];
            return $this->columnWithPrefix($identifier, $index->mysqlPrefixLengths[$identifier] ?? null) . $suffix;
        }, $index->columns);
    }

    /** A quoted column identifier, optionally with a prefix-length suffix (`` `col`(19) ``) — only MySqlGrammar ever has a non-null $prefixLength to apply. */
    protected function columnWithPrefix(string $column, ?int $prefixLength): string
    {
        return $this->quoteIdentifier($column);
    }
}
