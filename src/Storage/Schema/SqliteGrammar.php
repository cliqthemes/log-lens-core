<?php
declare(strict_types=1);

namespace LogLens\Storage\Schema;

/** SQLite DDL — the default, zero-config engine. */
final class SqliteGrammar extends AbstractGrammar
{
    public function name(): string
    {
        return 'sqlite';
    }

    protected function currentTimestamp(): string
    {
        return 'CURRENT_TIMESTAMP';
    }

    protected function extraStatementsForColumn(string $table, ColumnDefinition $column): array
    {
        return []; // case-insensitive uniqueness is inline (COLLATE NOCASE + UNIQUE) — see columnLine().
    }

    protected function columnLine(ColumnDefinition $column): string
    {
        $name = $this->quoteIdentifier($column->name);

        if ($column->type === 'increments') {
            return "{$name} INTEGER PRIMARY KEY";
        }

        if ($column->generated !== null) {
            return "{$name} " . $this->generatedColumnSql($column);
        }

        $type = match ($column->type) {
            'integer' => 'INTEGER',
            'real' => 'REAL',
            default => 'TEXT', // 'text' and 'string' — SQLite has no length-bounded string type
        };

        $sql = "{$name} {$type}";
        $sql .= $column->nullable ? '' : ' NOT NULL';
        if ($column->hasDefault) {
            $sql .= ' DEFAULT ' . $this->resolveDefault($column);
        }
        if ($column->unique) {
            $sql .= ' UNIQUE';
            if ($column->caseInsensitiveUnique) {
                $sql .= ' COLLATE NOCASE';
            }
        }
        return $sql;
    }

    private function generatedColumnSql(ColumnDefinition $column): string
    {
        $generated = $column->generated;
        $type = $column->type === 'integer' ? 'INTEGER' : 'TEXT';
        $expr = match ($generated->kind) {
            'substring' => "substr({$generated->sourceColumn},{$generated->start},{$generated->length})",
            // json_valid guards non-JSON values (context_preview is free text for
            // most occurrences, only JSON for nginx access rows) — an unguarded
            // json_extract on non-JSON text throws and fails the write, since
            // SQLite evaluates indexed generated columns at write time.
            'json' => "CASE WHEN json_valid({$generated->sourceColumn}) THEN "
                . ($generated->castType === 'integer'
                    ? "CAST(json_extract({$generated->sourceColumn},'\$.{$generated->jsonPath}') AS INTEGER)"
                    : "json_extract({$generated->sourceColumn},'\$.{$generated->jsonPath}')")
                . ' END',
        };
        return "{$type} GENERATED ALWAYS AS ({$expr}) VIRTUAL";
    }
}
