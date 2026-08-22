<?php
declare(strict_types=1);

namespace LogLens\Storage\Schema;

/**
 * MySQL/MariaDB DDL (opt-in via `LOG_LENS_DB_DRIVER=mysql`).
 *
 * Two rules migration authors must follow for MySQL to accept a column
 * (enforced by convention, not the type system — see {@see Blueprint}):
 *  - A column carrying a schema-level `->default()` must be declared with
 *    `->string()`, never `->text()` — MySQL cannot give a `TEXT`/`BLOB`
 *    column *any* default, literal or expression. (Every current migration
 *    already follows this; the two SQLite/Postgres columns that used to
 *    default an unbounded column — `config_json`, `severities` — simply have
 *    no schema-level default on any engine now, since the app always
 *    supplies them explicitly on INSERT.)
 *  - Case-insensitive uniqueness needs a bounded `->string()` too (MySQL
 *    cannot key a `TEXT` column without an explicit prefix length).
 */
final class MysqlGrammar extends AbstractGrammar
{
    private const CI_COLLATION = 'utf8mb4_0900_ai_ci';

    public function name(): string
    {
        return 'mysql';
    }

    public function supportsPartialIndex(): bool
    {
        return false; // no WHERE clause on CREATE INDEX
    }

    public function quoteIdentifier(string $name): string
    {
        return '`' . $name . '`';
    }

    protected function currentTimestamp(): string
    {
        return "DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%d %H:%i:%s')";
    }

    protected function tableOptions(): string
    {
        return ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=' . self::CI_COLLATION;
    }

    protected function extraStatementsForColumn(string $table, ColumnDefinition $column): array
    {
        return []; // case-insensitive uniqueness is inline (column collation + UNIQUE) — see columnLine().
    }

    /** MySQL cannot index/key an unbounded TEXT column without an explicit prefix length. */
    protected function columnWithPrefix(string $column, ?int $prefixLength): string
    {
        $quoted = $this->quoteIdentifier($column);
        return $prefixLength !== null ? "{$quoted}({$prefixLength})" : $quoted;
    }

    /** Unlike SQLite/Postgres, MySQL index names are scoped to their table — DROP INDEX must name it. */
    public function compileDropIndexes(Blueprint $blueprint): array
    {
        return array_map(
            fn (string $name): string => "DROP INDEX {$name} ON {$blueprint->table};",
            $blueprint->dropIndexes,
        );
    }

    protected function columnLine(ColumnDefinition $column): string
    {
        $name = $this->quoteIdentifier($column->name);

        if ($column->type === 'increments') {
            return "{$name} INT AUTO_INCREMENT PRIMARY KEY";
        }

        if ($column->generated !== null) {
            return "{$name} " . $this->generatedColumnSql($column);
        }

        $type = match ($column->type) {
            'integer' => 'INTEGER',
            'real' => 'REAL',
            'string' => "VARCHAR({$column->length})",
            default => 'TEXT',
        };

        $sql = "{$name} {$type}";
        if ($column->caseInsensitiveUnique) {
            $sql .= ' COLLATE ' . self::CI_COLLATION;
        }
        $sql .= $column->nullable ? '' : ' NOT NULL';
        if ($column->hasDefault) {
            // TEXT/BLOB/JSON columns cannot carry a *literal* DEFAULT at all;
            // as of 8.0.13 they accept an *expression* default instead, which
            // the grammar only recognizes when parenthesized — even for a
            // plain string constant. Blueprint's contract keeps this column
            // type='string' (VARCHAR) whenever it needs a default, so this
            // path is reached only for columns that can actually take one.
            $sql .= ' DEFAULT (' . $this->resolveDefault($column) . ')';
        }
        if ($column->unique) {
            $sql .= ' UNIQUE';
        }
        return $sql;
    }

    private function generatedColumnSql(ColumnDefinition $column): string
    {
        $generated = $column->generated;
        $type = match (true) {
            $column->type === 'integer' => 'INTEGER',
            $generated->kind === 'substring' => "VARCHAR({$generated->length})",
            $generated->mysqlVarcharLength !== null => "VARCHAR({$generated->mysqlVarcharLength})",
            default => 'TEXT',
        };
        $expr = match ($generated->kind) {
            'substring' => "SUBSTRING({$generated->sourceColumn},{$generated->start},{$generated->length})",
            // JSON_VALID() guards non-JSON values, same reason as SQLite:
            // context_preview is free text for most occurrences.
            'json' => "CASE WHEN JSON_VALID({$generated->sourceColumn}) THEN "
                . ($generated->castType === 'integer'
                    ? "CAST({$generated->sourceColumn}->>'\$.{$generated->jsonPath}' AS SIGNED)"
                    : "{$generated->sourceColumn}->>'\$.{$generated->jsonPath}'")
                . ' END',
        };
        return "{$type} GENERATED ALWAYS AS ({$expr}) VIRTUAL";
    }
}
